<?php

namespace App\Console\Commands;

use App\Models\BookSubmission;
use App\Services\Library\LibraryStorage;
use Illuminate\Console\Command;

/**
 * Conservative, idempotent maintenance reaper for bulk-import quarantine
 * leftovers. Bulk import copies each EPUB into library/incoming/{ulid}/
 * BEFORE the DB claim, so a hard crash, an ENOSPC copy failure, or a
 * concurrent-claim loser can leave a staging dir that no submission owns.
 *
 * WHAT IT MAY DELETE (and nothing else):
 *   1. library/incoming/{ulid} directories that NO live submission owns —
 *      i.e. no BookSubmission has incoming_path pointing inside them. A
 *      submission's incoming_path is nulled once its incoming file is
 *      cleaned up (RunStateMachine), so only truly orphaned dirs match. A
 *      dir touched more recently than --min-age-hours is skipped (it may be
 *      an in-flight import).
 *   2. Stale ".tmp-*" files under library/original/** — leftovers from
 *      interrupted content-addressed promotions — older than --min-age-hours.
 *
 * It NEVER touches:
 *   - the immutable originals themselves under library/original/** (only
 *     files whose basename starts with ".tmp-");
 *   - any live submission's incoming directory;
 *   - anything outside library/incoming and library/original.
 *
 * DEFAULTS TO --dry-run (report only). Pass --force to actually delete.
 */
class CleanupIngestionArtifacts extends Command
{
    protected $signature = 'mnemosyne:ingestion:cleanup
        {--force : Actually delete (default is a dry-run report only)}
        {--min-age-hours=1 : Only reap artifacts untouched for at least this many hours}';

    protected $description = 'Reap orphaned incoming staging dirs and stale original .tmp files (dry-run by default)';

    public function handle(LibraryStorage $storage): int
    {
        $force = (bool) $this->option('force');
        $minAgeHours = max(0.0, (float) $this->option('min-age-hours'));
        $cutoff = now()->getTimestamp() - (int) round($minAgeHours * 3600);
        $disk = $storage->disk();

        $this->info(sprintf(
            'ingestion cleanup — %s, min-age %sh',
            $force ? 'DELETE' : 'dry-run (report only; pass --force to delete)',
            $minAgeHours,
        ));

        // Every incoming dir a live submission still points into is off
        // limits, regardless of age or state.
        $ownedDirs = array_flip(
            BookSubmission::query()
                ->whereNotNull('incoming_path')
                ->pluck('incoming_path')
                ->map(fn ($path) => dirname((string) $path))
                ->all(),
        );

        $orphanDirs = 0;
        foreach ($disk->directories('library/incoming') as $dir) {
            if (isset($ownedDirs[$dir])) {
                continue; // live submission owns this dir — never touch
            }

            if ($this->newestMtime($disk, $disk->allFiles($dir)) > $cutoff) {
                continue; // too recent — possibly an in-flight import
            }

            $orphanDirs++;
            $this->line(($force ? 'deleted' : '[would delete]')." orphan incoming dir: {$dir}");

            if ($force) {
                $disk->deleteDirectory($dir);
            }
        }

        $staleTmp = 0;
        foreach ($disk->allFiles('library/original') as $file) {
            // Immutable originals are NEVER touched — only interrupted-
            // promotion temp files, which are named ".tmp-...".
            if (! str_starts_with(basename($file), '.tmp-')) {
                continue;
            }

            if ((int) $disk->lastModified($file) > $cutoff) {
                continue;
            }

            $staleTmp++;
            $this->line(($force ? 'deleted' : '[would delete]')." stale original tmp: {$file}");

            if ($force) {
                $disk->delete($file);
            }
        }

        $this->info(sprintf(
            'cleanup complete: %s %d orphan incoming dir(s), %d stale original tmp file(s)',
            $force ? 'deleted' : 'would delete',
            $orphanDirs,
            $staleTmp,
        ));

        return self::SUCCESS;
    }

    /** Newest mtime among $files; 0 (i.e. "very old") for an empty dir. */
    private function newestMtime($disk, array $files): int
    {
        $newest = 0;

        foreach ($files as $file) {
            $newest = max($newest, (int) $disk->lastModified($file));
        }

        return $newest;
    }
}
