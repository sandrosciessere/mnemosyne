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
 *
 * Quarantine hygiene: the EPUB is copied into a per-invocation staging dir
 * BEFORE the DB claim, so EVERY failure path (claim lost, submission throw,
 * copy/ENOSPC throw) deletes the staging copy it created. Whatever still
 * slips through (e.g. a hard crash mid-copy) is swept by
 * mnemosyne:ingestion:cleanup.
 */
class ImportDiscoveredFiles extends Command
{
    private const RESULT_IMPORTED = 'imported';

    private const RESULT_FAILED = 'failed';

    /** Another worker claimed this entry; we dropped our own staging copy. */
    private const RESULT_SKIPPED = 'skipped';

    /** Disk is too full to stage safely — stop the run rather than spin. */
    private const RESULT_OUT_OF_SPACE = 'out_of_space';

    /**
     * Prefix marking a NON-retryable (security/containment) failure reason
     * in discovery_entries.error. --retry-failed never re-queues these.
     */
    private const SECURITY_FAILURE_PREFIX = 'SECURITY: ';

    protected $signature = 'mnemosyne:library:import
        {run : Public id of a discovery run}
        {--priority=low : Ingestion priority for created submissions (high|normal|low)}
        {--limit=0 : Import at most N entries this invocation (0 = all)}
        {--retry-failed : Re-attempt entries in import_failed for transient reasons (never security/containment failures)}';

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

        if ($this->option('retry-failed')) {
            $this->requeueFailed($run);
        }

        $limit = max(0, (int) $this->option('limit'));
        $imported = 0;
        $failed = 0;
        $skipped = 0;
        $lastId = 0;
        $outOfSpace = false;

        while (! $outOfSpace) {
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

                $result = $this->importEntry($service, $storage, $run, $entry, $root, $priority);

                if ($result === self::RESULT_IMPORTED) {
                    $imported++;
                } elseif ($result === self::RESULT_SKIPPED) {
                    // Concurrent-claim loser: another worker imported it.
                    // NOT a failure, and must NOT inflate `imported`.
                    $skipped++;
                } elseif ($result === self::RESULT_OUT_OF_SPACE) {
                    $failed++;
                    $outOfSpace = true;

                    break;
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

        if ($outOfSpace) {
            $this->warn('stopped early: insufficient free disk space — free space and re-run (optionally with --retry-failed).');
        }

        $remaining = DiscoveryEntry::query()
            ->where('discovery_run_id', $run->id)
            ->where('status', 'discovered')
            ->count();

        $this->info(sprintf(
            'import finished: %d submissions created, %d failed, %d skipped (claimed elsewhere), %d still pending',
            $imported,
            $failed,
            $skipped,
            $remaining,
        ));

        return self::SUCCESS;
    }

    /**
     * Return failed entries to `discovered` so the main loop retries them —
     * EXCEPT security/containment failures (symlink/outside-root), which are
     * never auto-retried. Classification is by the stored error marker.
     */
    private function requeueFailed(DiscoveryRun $run): void
    {
        $requeued = DiscoveryEntry::query()
            ->where('discovery_run_id', $run->id)
            ->where('status', 'import_failed')
            ->where(function ($query) {
                $query->whereNull('error')
                    ->orWhere('error', 'not like', self::SECURITY_FAILURE_PREFIX.'%');
            })
            ->update(['status' => 'discovered', 'error' => null]);

        $this->info("--retry-failed: {$requeued} transient-failed entries re-queued (security failures left untouched)");
    }

    private function importEntry(
        SubmissionService $service,
        LibraryStorage $storage,
        DiscoveryRun $run,
        DiscoveryEntry $entry,
        string $root,
        IngestionPriority $priority,
    ): string {
        // Decode the AUTHORITATIVE relative_path (base64 of the raw bytes)
        // back to the EXACT on-disk bytes, then build the absolute path.
        $rawRelative = $entry->rawRelativePath();
        $absolute = $root.DIRECTORY_SEPARATOR.$rawRelative;

        // Re-validate at import time: the tree may have changed since the
        // scan, and untrusted paths must never escape the allowlisted root.
        $real = ($rawRelative !== '' && ! is_link($absolute)) ? realpath($absolute) : false;

        if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR) || ! is_file($real)) {
            // Security/containment: NEVER auto-retried.
            $this->markFailed($entry, 'source file missing, symlinked or outside the allowlisted root', retryable: false);

            return self::RESULT_FAILED;
        }

        // Free-space guard BEFORE staging: never start a copy that would
        // fill the disk. This is a retryable failure (space may return).
        $sizeBytes = (int) ($entry->size_bytes ?: @filesize($real) ?: 0);

        if (! $storage->hasFreeSpaceFor($sizeBytes)) {
            $this->markFailed($entry, 'insufficient free disk space; retry once space is available');

            return self::RESULT_OUT_OF_SPACE;
        }

        // Copy first (idempotent: unique staging dir; a crash before the
        // transaction leaves only an orphan staging dir, never a duplicate
        // submission — and every failure path below deletes it). Then flip
        // the entry + create the submission in ONE transaction.
        $stagingDir = 'library/incoming/'.strtolower((string) Str::ulid());
        $incomingPath = $stagingDir.'/source.epub';
        $tempPath = $stagingDir.'/.tmp-source';

        $sourceStream = @fopen($real, 'rb');

        if ($sourceStream === false) {
            $this->markFailed($entry, 'source file unreadable');

            return self::RESULT_FAILED;
        }

        try {
            $storage->disk()->writeStream($tempPath, $sourceStream);
        } catch (Throwable $exception) {
            $this->deleteStaging($storage, $stagingDir);
            $this->markFailed($entry, 'copy failed: '.$exception->getMessage());

            return self::RESULT_FAILED;
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }

        try {
            if (! $storage->disk()->move($tempPath, $incomingPath)) {
                throw new \RuntimeException('atomic move into the incoming quarantine failed');
            }
        } catch (Throwable $exception) {
            $this->deleteStaging($storage, $stagingDir);
            $this->markFailed($entry, 'copy failed: '.$exception->getMessage());

            return self::RESULT_FAILED;
        }

        $claimed = false;

        try {
            DB::transaction(function () use ($service, $run, $entry, $incomingPath, $priority, &$claimed) {
                $locked = DiscoveryEntry::query()->lockForUpdate()->find($entry->id);

                if ($locked === null || $locked->status !== 'discovered') {
                    return; // Another import worker already took it.
                }

                $submission = $service->createFromFilesystem(
                    $incomingPath,
                    basename($locked->display_path ?? $incomingPath),
                    [
                        'source' => $run->source_name,
                        'discovery_run' => $run->public_id,
                        // Human-readable + byte-exact provenance. Never store
                        // the raw (possibly invalid-UTF-8) bytes in JSON.
                        'relative_path' => $locked->display_path,
                        'relative_path_b64' => $locked->relative_path,
                        'author_hint' => $locked->author_hint,
                        'title_hint' => $locked->title_hint,
                    ],
                    $priority,
                );

                $locked->forceFill([
                    'status' => 'imported',
                    'book_submission_id' => $submission->id,
                    'imported_at' => now(),
                ])->save();

                $claimed = true;
            });
        } catch (Throwable $exception) {
            $this->deleteStaging($storage, $stagingDir);
            $this->markFailed($entry, 'submission failed: '.$exception->getMessage());

            return self::RESULT_FAILED;
        }

        if (! $claimed) {
            // We lost the claim race: the winner owns the entry and its own
            // staging copy. Drop ours so it does not orphan, and do NOT count
            // it as imported.
            $this->deleteStaging($storage, $stagingDir);

            return self::RESULT_SKIPPED;
        }

        return self::RESULT_IMPORTED;
    }

    /** Delete a staging dir this invocation created (incoming area only). */
    private function deleteStaging(LibraryStorage $storage, string $stagingDir): void
    {
        if (str_starts_with($stagingDir, 'library/incoming/') && $stagingDir !== 'library/incoming') {
            $storage->disk()->deleteDirectory($stagingDir);
        }
    }

    private function markFailed(DiscoveryEntry $entry, string $error, bool $retryable = true): void
    {
        $stored = $retryable ? $error : self::SECURITY_FAILURE_PREFIX.$error;

        // Conditional, atomic transition: only fail an entry still in the
        // `discovered` state this worker claimed from. If a concurrent worker
        // already advanced it to `imported`, this update affects zero rows and
        // we converge on that success instead of clobbering it back to
        // import_failed — which `--retry-failed` would otherwise re-import,
        // creating a duplicate submission for an entry that already succeeded.
        $affected = DiscoveryEntry::query()
            ->whereKey($entry->id)
            ->where('status', 'discovered')
            ->update([
                'status' => 'import_failed',
                'error' => mb_substr($stored, 0, 1000),
            ]);

        if ($affected === 0) {
            $this->warn("entry {$entry->display_path}: {$error} (ignored — entry already advanced past 'discovered')");

            return;
        }

        $this->warn("entry {$entry->display_path}: {$error}");
    }
}
