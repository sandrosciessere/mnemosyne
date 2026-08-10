<?php

namespace App\Console\Commands;

use App\Models\DiscoveryEntry;
use App\Models\DiscoveryRun;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Filesystem discovery — phase one of bulk import. STRICTLY READ-ONLY
 * with respect to the source library: it records a persistent manifest
 * (DiscoveryRun + DiscoveryEntry) and never copies files or creates
 * submissions. A 100k+ scan interrupted at any point resumes from its
 * durable cursor with `--resume`. Phase two is mnemosyne:library:import.
 */
class DiscoverLibraryFiles extends Command
{
    private const BATCH_SIZE = 500;

    protected $signature = 'mnemosyne:library:discover
        {--source= : Import source name (see MNEMOSYNE_IMPORT_SOURCES)}
        {--resume= : Public id of an interrupted discovery run to resume}
        {--dry-run : Walk and count without persisting anything}
        {--limit=0 : Stop after N epub files this invocation (0 = no limit); resume later}';

    protected $description = 'Read-only scan of an allowlisted library root into a persistent, resumable discovery manifest';

    public function handle(): int
    {
        $resumeId = (string) $this->option('resume');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $sources = (array) config('mnemosyne.import_sources');

        if ($resumeId !== '') {
            $run = DiscoveryRun::query()->where('public_id', $resumeId)->first();

            if ($run === null) {
                $this->error('Unknown discovery run.');

                return self::FAILURE;
            }

            $sourceName = $run->source_name;
        } else {
            $sourceName = (string) $this->option('source');
            $run = null;
        }

        if ($sourceName === '' || ! array_key_exists($sourceName, $sources)) {
            $this->error(
                $sources === []
                    ? 'No import sources configured (MNEMOSYNE_IMPORT_SOURCES is empty).'
                    : 'Unknown source. Configured: '.implode(', ', array_keys($sources)),
            );

            return self::FAILURE;
        }

        $root = realpath($sources[$sourceName]);

        if ($root === false || ! is_dir($root)) {
            $this->error('Import root does not exist or is not a directory.');

            return self::FAILURE;
        }

        if (! $dryRun && $run === null) {
            $run = new DiscoveryRun;
            $run->forceFill([
                'source_name' => $sourceName,
                'root_path' => $root,
                'status' => 'running',
                'started_at' => now(),
            ])->save();
            $this->info("discovery run {$run->public_id} started");
        } elseif ($run !== null) {
            $run->forceFill(['status' => 'running', 'finished_at' => null])->save();
            $this->info("resuming discovery run {$run->public_id} after '{$run->last_path}'");
        }

        $counters = [
            'files_seen' => 0,
            'epubs_found' => 0,
            'entries_created' => 0,
            'skipped_outside_root' => 0,
            'unreadable' => 0,
        ];
        $this->flushedCounters = array_fill_keys(array_keys($counters), 0);
        $buffer = [];
        $lastPath = $run?->last_path;
        $limitReached = false;

        foreach ($this->walk($root, '', $counters) as $relative) {
            $counters['files_seen']++;

            if (! str_ends_with(strtolower($relative), '.epub')) {
                continue;
            }

            // Resume: skip everything at or before the durable cursor
            // (walk order is deterministic, so this is exact).
            if ($lastPath !== null && $this->pathCompare($relative, $lastPath) <= 0) {
                continue;
            }

            $absolute = $root.DIRECTORY_SEPARATOR.$relative;

            // Containment: resolved path must stay inside the root (the
            // walker already refuses symlinks; this is defense in depth).
            $real = realpath($absolute);
            if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                $counters['skipped_outside_root']++;

                continue;
            }

            $counters['epubs_found']++;

            if ($dryRun) {
                $this->line("[dry-run] {$relative}");
            } else {
                $segments = explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $relative));
                $buffer[] = [
                    'discovery_run_id' => $run->id,
                    'relative_path' => mb_substr($relative, 0, 1000),
                    'size_bytes' => @filesize($absolute) ?: null,
                    'author_hint' => count($segments) >= 3 ? mb_substr($segments[0], 0, 500) : null,
                    'title_hint' => count($segments) >= 3 ? mb_substr($segments[1], 0, 500) : null,
                    'status' => 'discovered',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($buffer) >= self::BATCH_SIZE) {
                    $this->flush($run, $buffer, $counters);
                    $buffer = [];
                }
            }

            if ($counters['epubs_found'] % 1000 === 0) {
                $this->info("progress: {$counters['epubs_found']} epubs found…");
            }

            if ($limit > 0 && $counters['epubs_found'] >= $limit) {
                $limitReached = true;
                $this->info("limit of {$limit} reached — resume later with --resume={$run?->public_id}");

                break;
            }
        }

        if (! $dryRun) {
            $this->flush($run, $buffer, $counters);
            $run->forceFill([
                'status' => $limitReached ? 'aborted' : 'completed',
                'finished_at' => now(),
            ])->save();
        }

        $this->info(sprintf(
            '%s: %d files seen, %d epubs, %d entries persisted, %d skipped (outside root), %d unreadable',
            $dryRun ? 'dry-run complete' : ($limitReached ? 'discovery interrupted (resumable)' : 'discovery complete'),
            $counters['files_seen'],
            $counters['epubs_found'],
            $counters['entries_created'],
            $counters['skipped_outside_root'],
            $counters['unreadable'],
        ));

        if (! $dryRun) {
            $this->info("next step: php artisan mnemosyne:library:import {$run->public_id}");
        }

        return self::SUCCESS;
    }

    /**
     * Deterministic, bounded-memory DFS: each directory listing is sorted,
     * symlinks are never followed, unreadable directories are counted and
     * skipped. Yields file paths relative to the root, in a total order
     * that pathCompare() reproduces (the resume cursor relies on it).
     */
    private function walk(string $root, string $dir, array &$counters): Generator
    {
        $absolute = $dir === '' ? $root : $root.DIRECTORY_SEPARATOR.$dir;
        $names = @scandir($absolute, SCANDIR_SORT_ASCENDING);

        if ($names === false) {
            $counters['unreadable']++;

            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relative = $dir === '' ? $name : $dir.DIRECTORY_SEPARATOR.$name;
            $path = $root.DIRECTORY_SEPARATOR.$relative;

            if (is_link($path)) {
                continue; // Never follow symlinks — in or out of root.
            }

            if (is_dir($path)) {
                yield from $this->walk($root, $relative, $counters);
            } elseif (is_file($path)) {
                yield $relative;
            } elseif (! file_exists($path)) {
                $counters['unreadable']++;
            }
        }
    }

    /**
     * Segment-wise lexicographic comparison matching the DFS order of
     * walk() (a plain full-string strcmp would disagree with it around
     * '/' vs other low-codepoint characters).
     */
    private function pathCompare(string $a, string $b): int
    {
        $aSegments = explode(DIRECTORY_SEPARATOR, $a);
        $bSegments = explode(DIRECTORY_SEPARATOR, $b);
        $count = min(count($aSegments), count($bSegments));

        for ($index = 0; $index < $count; $index++) {
            $comparison = strcmp($aSegments[$index], $bSegments[$index]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return count($aSegments) <=> count($bSegments);
    }

    /** @var array<string, int> counters already folded into the run row */
    private array $flushedCounters = [];

    /** Persist a batch + counter deltas + resume cursor atomically. */
    private function flush(DiscoveryRun $run, array $buffer, array &$counters): void
    {
        DB::transaction(function () use ($run, $buffer, &$counters) {
            if ($buffer !== []) {
                // insertOrIgnore + the per-run unique(relative_path): safe
                // against overlapping resumes — never duplicate entries.
                $counters['entries_created'] += DiscoveryEntry::query()->insertOrIgnore($buffer);
            }

            $updates = [];
            foreach ($counters as $key => $value) {
                $updates[$key] = $run->{$key} + ($value - $this->flushedCounters[$key]);
            }

            if ($buffer !== []) {
                $updates['last_path'] = end($buffer)['relative_path'];
            }

            $run->forceFill($updates)->save();
            $this->flushedCounters = $counters;
        });
    }
}
