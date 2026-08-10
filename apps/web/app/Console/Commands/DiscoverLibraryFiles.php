<?php

namespace App\Console\Commands;

use App\Enums\IngestionPriority;
use App\Services\Library\LibraryStorage;
use App\Services\Library\SubmissionService;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Filesystem import foundation. Streams an allowlisted directory tree
 * (Author/Title/book.epub convention, at 100k+ scale) and creates
 * filesystem-source submissions. Directory names are recorded as HINTS
 * only — bibliographic truth comes from parsing, never from paths.
 */
class DiscoverLibraryFiles extends Command
{
    protected $signature = 'mnemosyne:library:discover
        {--source= : Import source name (see MNEMOSYNE_IMPORT_SOURCES)}
        {--dry-run : List what would be imported without copying or creating anything}
        {--limit=0 : Stop after N files (0 = no limit)}
        {--priority=low : Ingestion priority for created submissions (high|normal|low)}';

    protected $description = 'Discover .epub files in an allowlisted import root and create filesystem submissions';

    public function handle(SubmissionService $service, LibraryStorage $storage): int
    {
        $sources = (array) config('mnemosyne.import_sources');
        $sourceName = (string) $this->option('source');

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

        $priority = IngestionPriority::tryFrom((string) $this->option('priority'));

        if ($priority === null) {
            $this->error('Invalid priority (use high|normal|low).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        // Lazy recursive traversal: nothing is accumulated in memory and
        // directory symlinks are not followed (child dirs are opened with
        // the same flags, which exclude FOLLOW_SYMLINKS).
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD, // unreadable dirs: skip
        );

        $found = 0;
        $created = 0;
        $skipped = 0;

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'epub') {
                continue;
            }

            // Containment check: the resolved path must stay inside the
            // allowlisted root (defends against symlinked files/dirs).
            $real = $file->getRealPath();
            if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                $skipped++;

                continue;
            }

            $found++;
            $relative = ltrim(substr($real, strlen($root)), DIRECTORY_SEPARATOR);

            if ($dryRun) {
                $this->line("[dry-run] {$relative}");
            } else {
                $this->importFile($service, $storage, $sourceName, $root, $relative, $priority);
                $created++;
            }

            if ($found % 500 === 0) {
                $this->info("progress: {$found} files discovered…");
            }

            if ($limit > 0 && $found >= $limit) {
                $this->info("limit of {$limit} reached, stopping.");

                break;
            }
        }

        $this->info(sprintf(
            '%s: %d epub files found, %d submissions created, %d skipped (outside root)',
            $dryRun ? 'dry-run complete' : 'discovery complete',
            $found,
            $created,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function importFile(
        SubmissionService $service,
        LibraryStorage $storage,
        string $sourceName,
        string $root,
        string $relative,
        IngestionPriority $priority,
    ): void {
        $segments = explode(DIRECTORY_SEPARATOR, $relative);

        // Copy into the incoming area with an atomic rename; the pipeline
        // (hash stage) dedups so re-running discovery is safe.
        $stagingDir = 'library/incoming/'.strtolower((string) Str::ulid());
        $incomingPath = $stagingDir.'/source.epub';
        $tempPath = $stagingDir.'/.tmp-source';

        $sourceStream = fopen($root.DIRECTORY_SEPARATOR.$relative, 'rb');

        if ($sourceStream === false) {
            $this->warn("unreadable, skipped: {$relative}");

            return;
        }

        try {
            $storage->disk()->writeStream($tempPath, $sourceStream);
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }

        $storage->disk()->move($tempPath, $incomingPath);

        $submission = $service->createFromFilesystem(
            $incomingPath,
            basename($relative),
            [
                'source' => $sourceName,
                'relative_path' => $relative,
                // Hints from the Author/Title/file.epub convention — never
                // trusted as bibliographic truth.
                'author_hint' => count($segments) >= 3 ? $segments[0] : null,
                'title_hint' => count($segments) >= 3 ? $segments[1] : null,
            ],
            $priority,
        );

        $this->line("created submission {$submission->public_id} for {$relative}");
    }
}
