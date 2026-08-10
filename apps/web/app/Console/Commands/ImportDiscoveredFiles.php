<?php

namespace App\Console\Commands;

use App\Enums\IngestionPriority;
use App\Models\DiscoveryEntry;
use App\Models\DiscoveryRun;
use App\Services\Library\LibraryStorage;
use App\Services\Library\SubmissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import — phase two of bulk import. Consumes the persistent discovery
 * manifest: copies each discovered file into the incoming quarantine and
 * creates a filesystem submission. Restart-safe: an entry moves from
 * `discovered` to `imported` atomically with its submission, so re-running
 * after an interruption never duplicates submissions.
 */
class ImportDiscoveredFiles extends Command
{
    protected $signature = 'mnemosyne:library:import
        {run : Public id of a discovery run}
        {--priority=low : Ingestion priority for created submissions (high|normal|low)}
        {--limit=0 : Import at most N entries this invocation (0 = all)}';

    protected $description = 'Create submissions from a persistent discovery manifest (restart-safe)';

    public function handle(SubmissionService $service, LibraryStorage $storage): int
    {
        $run = DiscoveryRun::query()->where('public_id', $this->argument('run'))->first();

        if ($run === null) {
            $this->error('Unknown discovery run.');

            return self::FAILURE;
        }

        $priority = IngestionPriority::tryFrom((string) $this->option('priority'));

        if ($priority === null) {
            $this->error('Invalid priority (use high|normal|low).');

            return self::FAILURE;
        }

        $sources = (array) config('mnemosyne.import_sources');

        if (! array_key_exists($run->source_name, $sources)) {
            $this->error("Source '{$run->source_name}' is no longer allowlisted.");

            return self::FAILURE;
        }

        $root = realpath($sources[$run->source_name]);

        if ($root === false || ! is_dir($root)) {
            $this->error('Import root does not exist any more.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $imported = 0;
        $failed = 0;
        $lastId = 0;

        while (true) {
            $entries = DiscoveryEntry::query()
                ->where('discovery_run_id', $run->id)
                ->where('status', 'discovered')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(200)
                ->get();

            if ($entries->isEmpty()) {
                break;
            }

            foreach ($entries as $entry) {
                $lastId = $entry->id;

                if ($this->importEntry($service, $storage, $run, $entry, $root, $priority)) {
                    $imported++;
                } else {
                    $failed++;
                }

                if ($imported % 100 === 0 && $imported > 0) {
                    $this->info("progress: {$imported} imported…");
                }

                if ($limit > 0 && $imported >= $limit) {
                    break 2;
                }
            }
        }

        $remaining = DiscoveryEntry::query()
            ->where('discovery_run_id', $run->id)
            ->where('status', 'discovered')
            ->count();

        $this->info(sprintf(
            'import finished: %d submissions created, %d failed, %d still pending',
            $imported,
            $failed,
            $remaining,
        ));

        return self::SUCCESS;
    }

    private function importEntry(
        SubmissionService $service,
        LibraryStorage $storage,
        DiscoveryRun $run,
        DiscoveryEntry $entry,
        string $root,
        IngestionPriority $priority,
    ): bool {
        $absolute = $root.DIRECTORY_SEPARATOR.$entry->relative_path;

        // Re-validate at import time: the tree may have changed since the
        // scan, and untrusted paths must never escape the allowlisted root.
        $real = (! is_link($absolute)) ? realpath($absolute) : false;

        if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR) || ! is_file($real)) {
            $this->markFailed($entry, 'source file missing, symlinked or outside the allowlisted root');

            return false;
        }

        // Copy first (idempotent: unique staging dir; a crash before the
        // transaction leaves only an orphan temp dir, never a duplicate
        // submission). Then flip the entry + create the submission in ONE
        // transaction.
        $stagingDir = 'library/incoming/'.strtolower((string) Str::ulid());
        $incomingPath = $stagingDir.'/source.epub';
        $tempPath = $stagingDir.'/.tmp-source';

        $sourceStream = @fopen($real, 'rb');

        if ($sourceStream === false) {
            $this->markFailed($entry, 'source file unreadable');

            return false;
        }

        try {
            $storage->disk()->writeStream($tempPath, $sourceStream);
        } catch (Throwable $exception) {
            $this->markFailed($entry, 'copy failed: '.$exception->getMessage());

            return false;
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }

        $storage->disk()->move($tempPath, $incomingPath);

        try {
            DB::transaction(function () use ($service, $run, $entry, $incomingPath, $priority) {
                $locked = DiscoveryEntry::query()->lockForUpdate()->find($entry->id);

                if ($locked === null || $locked->status !== 'discovered') {
                    return; // Another import worker already took it.
                }

                $submission = $service->createFromFilesystem(
                    $incomingPath,
                    basename($entry->relative_path),
                    [
                        'source' => $run->source_name,
                        'discovery_run' => $run->public_id,
                        'relative_path' => $entry->relative_path,
                        'author_hint' => $entry->author_hint,
                        'title_hint' => $entry->title_hint,
                    ],
                    $priority,
                );

                $locked->forceFill([
                    'status' => 'imported',
                    'book_submission_id' => $submission->id,
                    'imported_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            $this->markFailed($entry, 'submission failed: '.$exception->getMessage());

            return false;
        }

        return true;
    }

    private function markFailed(DiscoveryEntry $entry, string $error): void
    {
        $entry->forceFill([
            'status' => 'import_failed',
            'error' => mb_substr($error, 0, 1000),
        ])->save();

        $this->warn("entry {$entry->relative_path}: {$error}");
    }
}
