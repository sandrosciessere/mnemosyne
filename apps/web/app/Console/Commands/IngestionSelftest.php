<?php

namespace App\Console\Commands;

use App\Enums\IngestionPriority;
use App\Enums\IngestionRunStatus;
use App\Models\BookSubmission;
use App\Models\Contributor;
use App\Services\Library\LibraryStorage;
use App\Services\Library\SubmissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Repeatable staging/production smoke test: pushes ONE synthetic EPUB
 * through the real pipeline (real queues, real worker) and verifies the
 * outcome. Synthetic content only; cleans up after itself by default.
 */
class IngestionSelftest extends Command
{
    protected $signature = 'mnemosyne:ingestion:selftest
        {--keep : Keep the created rows and files for inspection}
        {--timeout=300 : Seconds to wait for the pipeline to finish}';

    protected $description = 'Run one synthetic EPUB through the real ingestion pipeline and verify the result';

    public function handle(SubmissionService $service, LibraryStorage $storage): int
    {
        // Unique text every run → unique sha256 and content fingerprint,
        // so the smoke never collides with dedup from a previous run.
        $nonce = strtolower((string) Str::ulid());

        $stagingDir = 'library/incoming/'.strtolower((string) Str::ulid());
        $relativePath = $stagingDir.'/source.epub';
        $absolute = $storage->absolutePath($relativePath);
        @mkdir(dirname($absolute), 0750, true);

        $this->buildSyntheticEpub($absolute, $nonce);
        $this->info('synthetic EPUB created ('.filesize($absolute).' bytes)');

        $submission = $service->createFromFilesystem(
            $relativePath,
            "selftest-{$nonce}.epub",
            ['source' => 'selftest', 'relative_path' => "selftest/{$nonce}.epub"],
            IngestionPriority::High,
        );

        $service->approve($submission, actor: null);
        $this->info("submission {$submission->public_id} approved and queued (high priority)");

        $run = $submission->refresh()->latestRun;
        $deadline = time() + (int) $this->option('timeout');

        while (time() < $deadline) {
            $run->refresh();

            if ($run->status->isTerminal() || $run->status === IngestionRunStatus::NeedsReview) {
                break;
            }

            sleep(2);
        }

        $ok = $this->verify($submission->refresh(), $storage);

        if (! $this->option('keep')) {
            $this->cleanup($submission, $storage);
        } else {
            $this->warn('kept selftest data (submission '.$submission->public_id.')');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function verify(BookSubmission $submission, LibraryStorage $storage): bool
    {
        $run = $submission->latestRun;
        $asset = $submission->asset;
        $ok = true;

        $check = function (string $label, bool $condition) use (&$ok) {
            $this->line(($condition ? '[OK]   ' : '[FAIL] ').$label);
            $ok = $ok && $condition;
        };

        $check('run succeeded', $run?->status === IngestionRunStatus::Succeeded);
        $check('progress 100', $run?->progress === 100);
        $check('asset ready_for_enrichment', $asset?->ingestion_status?->value === 'ready_for_enrichment');
        $check('content fingerprint present', $asset?->content_sha256 !== null);
        $check('original promoted', $asset?->storage_path !== null && $storage->disk()->exists($asset->storage_path));
        $check('edition reconciled', $asset?->edition !== null);
        $check(
            'all five stages attempted',
            $run !== null && $run->attempts()->count() >= 5,
        );
        $check(
            'artifacts written',
            $asset !== null && $storage->disk()->exists($asset->artifactDir($run->pipeline_version).'/structure.json'),
        );

        if (! $ok && $run !== null) {
            $this->error("run status: {$run->status->value}, stage: {$run->current_stage?->value}, error: {$run->last_error_code}");
        }

        return $ok;
    }

    private function cleanup(BookSubmission $submission, LibraryStorage $storage): void
    {
        $asset = $submission->asset;

        // Delete ONLY what this selftest created, by exact rows/paths.
        if ($asset !== null) {
            if ($asset->storage_path !== null && $storage->disk()->exists($asset->storage_path)) {
                $storage->disk()->delete($asset->storage_path);
            }
            $storage->disk()->deleteDirectory('library/extracted/'.$asset->public_id);
        }

        $storage->cleanupIncoming($submission);

        // FK cascades remove runs/attempts/events/grants with their parents.
        $submission->runs()->delete();
        $submission->delete();

        $edition = $asset?->edition;
        $asset?->delete();

        // Remove the provisional Edition/Work/Contributors this selftest
        // created — only when nothing else references them.
        if ($edition !== null && $edition->assets()->count() === 0) {
            $contributorIds = $edition->contributors()->pluck('contributors.id');
            $work = $edition->work;
            $edition->delete();

            if ($work->editions()->count() === 0) {
                $work->delete();
            }

            Contributor::query()
                ->whereIn('id', $contributorIds)
                ->whereDoesntHave('editions')
                ->delete();
        }

        $this->info('selftest data cleaned up');
    }

    private function buildSyntheticEpub(string $path, string $nonce): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/container.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
              <rootfiles>
                <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
              </rootfiles>
            </container>
            XML);

        $zip->addFromString('OEBPS/content.opf', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" xml:lang="en">
              <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
                <dc:identifier id="pub-id">urn:uuid:00000000-5e1f-4e57-0000-{$this->uuidTail($nonce)}</dc:identifier>
                <dc:title>Selftest Synthetic Book {$nonce}</dc:title>
                <dc:creator id="c1">Selftest Author</dc:creator>
                <meta refines="#c1" property="role" scheme="marc:relators">aut</meta>
                <dc:language>en</dc:language>
                <dc:publisher>Mnemosyne Selftest Press</dc:publisher>
                <dc:date>2026-01-01</dc:date>
                <meta property="dcterms:modified">2026-01-01T00:00:00Z</meta>
              </metadata>
              <manifest>
                <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
                <item id="ch1" href="ch1.xhtml" media-type="application/xhtml+xml"/>
                <item id="ch2" href="ch2.xhtml" media-type="application/xhtml+xml"/>
              </manifest>
              <spine>
                <itemref idref="ch1"/>
                <itemref idref="ch2"/>
              </spine>
            </package>
            XML);

        $zip->addFromString('OEBPS/nav.xhtml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
            <head><title>Contents</title></head>
            <body><nav epub:type="toc"><ol>
              <li><a href="ch1.xhtml#h1">Chapter One</a></li>
              <li><a href="ch2.xhtml#h2">Chapter Two</a></li>
            </ol></nav></body>
            </html>
            XML);

        foreach ([1, 2] as $chapter) {
            $zip->addFromString("OEBPS/ch{$chapter}.xhtml", <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
                <head><title>Chapter {$chapter}</title></head>
                <body>
                  <h1 id="h{$chapter}">Chapter {$chapter}</h1>
                  <p>Synthetic selftest paragraph for run {$nonce}, chapter {$chapter}.</p>
                  <p>This content is generated on the fly and cleaned up afterwards.</p>
                </body>
                </html>
                XML);
        }

        $zip->close();
    }

    private function uuidTail(string $nonce): string
    {
        return substr(md5($nonce), 0, 12);
    }
}
