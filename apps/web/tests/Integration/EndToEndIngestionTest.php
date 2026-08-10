<?php

namespace Tests\Integration;

use App\Enums\IngestionRunStatus;
use App\Models\BookAccessGrant;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\DuplicateCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticEpub;

/**
 * THE acceptance test: a synthetic EPUB crosses the real pipeline —
 * Laravel orchestration, database queue, and the REAL Python worker
 * (ai-worker-test) parsing actual bytes. Nothing is mocked.
 */
class EndToEndIngestionTest extends IntegrationTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::forgetDisk('data');

        // Fail fast if the real worker is not up.
        try {
            $response = Http::timeout(3)
                ->get(config('mnemosyne.worker.base_url').'/health/live');
            if (! $response->ok()) {
                throw new \RuntimeException('worker live check failed');
            }
        } catch (\Throwable $exception) {
            if (getenv('RUN_INTEGRATION') === '1') {
                $this->fail('ai-worker-test is not reachable: '.$exception->getMessage());
            }

            $this->markTestSkipped('ai-worker-test is not reachable.');
        }
    }

    private function submitEpub(string $builderMethod, ?User $user = null, string $filename = 'book.epub'): BookSubmission
    {
        $user = $user ?? User::factory()->create();
        $admin = User::factory()->admin()->create();

        $epubPath = sys_get_temp_dir().'/mnemosyne-e2e-'.uniqid().'.epub';
        SyntheticEpub::{$builderMethod}($epubPath);

        try {
            $this->actingAs($user)->post('/library/submissions', [
                'epub' => new UploadedFile($epubPath, $filename, 'application/epub+zip', null, true),
            ])->assertRedirect();
        } finally {
            File::delete($epubPath);
        }

        $submission = BookSubmission::query()->where('user_id', $user->id)->latest('id')->first();
        $this->actingAs($admin)->post('/admin/submissions/'.$submission->public_id.'/approve')->assertRedirect();
        $this->drainIngestionQueue();

        return $submission->refresh();
    }

    public function test_epub3_reaches_ready_for_enrichment_with_artifacts_and_citations(): void
    {
        $user = User::factory()->create();
        $submission = $this->submitEpub('epub3', $user, 'synthetic-chronicle.epub');

        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status, 'run: '.$run->last_error_message);
        $this->assertSame(100, $run->progress);

        $asset = $run->asset;
        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $this->assertSame('3.0', $asset->epub_version);
        $this->assertNotNull($asset->content_sha256);
        $this->assertSame('1', $asset->content_fingerprint_version);

        // Original promoted to content-addressed storage, incoming cleaned.
        $originalAbsolute = $this->testDataRoot.'/'.BookAsset::originalStoragePath($asset->sha256);
        $this->assertFileExists($originalAbsolute);
        $this->assertSame($asset->sha256, hash_file('sha256', $originalAbsolute));
        $this->assertNull($submission->refresh()->incoming_path);

        // Artifacts on disk, versioned, with manifest.
        $artifactDir = $this->testDataRoot.'/library/extracted/'.$asset->public_id.'/v1';
        foreach (['manifest.json', 'metadata.json', 'structure.json'] as $artifact) {
            $this->assertFileExists($artifactDir.'/'.$artifact);
        }
        $spineFiles = glob($artifactDir.'/spine/*.jsonl');
        $this->assertCount(3, $spineFiles, 'one JSONL per spine item');

        $manifest = json_decode(file_get_contents($artifactDir.'/manifest.json'), true);
        $this->assertSame($asset->sha256, $manifest['source_sha256']);
        foreach (['parse', 'normalize', 'structure'] as $stage) {
            $this->assertArrayHasKey($stage, $manifest['stages']);
        }

        // Citation readiness: every node carries source references.
        $firstNode = json_decode(explode("\n", trim(file_get_contents($spineFiles[0])))[0], true);
        $this->assertSame(0, $firstNode['spine_index']);
        $this->assertNotEmpty($firstNode['node_id']);
        $this->assertNotEmpty($firstNode['source']['href']);
        $this->assertArrayHasKey('fragment', $firstNode['source']);
        $this->assertArrayHasKey('heading_path', $firstNode);
        $this->assertArrayHasKey('ordinal', $firstNode);

        // Structure artifact: TOC + sections + fingerprint.
        $structure = json_decode(file_get_contents($artifactDir.'/structure.json'), true);
        $this->assertSame($asset->content_sha256, $structure['content_sha256']);
        $this->assertNotEmpty($structure['toc']);
        $this->assertNotEmpty($structure['sections']);

        // Bibliographic result from REAL OPF parsing.
        $this->assertSame('The Synthetic Chronicle', $asset->edition->title);
        $this->assertSame('en', $asset->edition->language);
        $this->assertSame('Mnemosyne Test Press', $asset->edition->publisher);
        $this->assertSame(2024, $asset->edition->publication_year);
        $roles = $asset->edition->contributors->pluck('pivot.role', 'pivot.credited_as');
        $this->assertSame('aut', $roles['Ada Example']);
        $this->assertSame('trl', $roles['Turing Translator']);
        $schemes = $asset->edition->identifiers->pluck('value', 'scheme');
        $this->assertSame('9780316769488', $schemes['isbn13']);
        $this->assertArrayHasKey('uuid', $schemes);
        $this->assertSame('The Synthetic Chronicle', $asset->edition->work->canonical_title);

        // Structure summary mirrored on the asset for list views.
        $this->assertSame(3, $asset->structure_summary['spine_items']);
        $this->assertGreaterThan(0, $asset->structure_summary['sections']);

        // Access + audit trail.
        $this->assertTrue(BookAccessGrant::query()->where('user_id', $user->id)->where('book_asset_id', $asset->id)->exists());
        $this->assertSame(
            ['hash', 'validate', 'parse', 'normalize', 'structure'],
            $run->attempts->pluck('stage.value')->all(),
        );
        $types = $run->events->pluck('type');
        $this->assertContains('run.succeeded', $types);
        $this->assertContains('asset.promoted_to_original', $types);
        $this->assertContains('asset.reconciled', $types);
    }

    public function test_epub2_with_ncx_is_processed(): void
    {
        $submission = $this->submitEpub('epub2', filename: 'older-volume.epub');

        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status, 'run: '.$run->last_error_message);

        $asset = $run->asset;
        $this->assertStringStartsWith('2', $asset->epub_version);
        $this->assertSame('An Older Synthetic Volume', $asset->edition->title);
        $this->assertSame('it', $asset->edition->language);
        $this->assertSame('aut', $asset->edition->contributors->first()->pivot->role);
        $this->assertGreaterThanOrEqual(2, $asset->structure_summary['toc_entries']);
    }

    public function test_exact_duplicate_end_to_end(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $first = $this->submitEpub('epub3', $userA);
        $second = $this->submitEpub('epub3', $userB);

        $this->assertSame(1, BookAsset::query()->count());
        $asset = BookAsset::query()->sole();

        $this->assertTrue($second->refresh()->is_exact_duplicate);
        $this->assertSame(IngestionRunStatus::Succeeded, $second->latestRun->status);
        $this->assertSame(['hash'], $second->latestRun->attempts->pluck('stage.value')->all(), 'no reprocessing');

        // Exactly one physical original.
        $originals = File::allFiles($this->testDataRoot.'/library/original');
        $this->assertCount(1, $originals);

        $this->assertSame(2, $asset->submissions()->count());
        foreach ([$userA, $userB] as $user) {
            $this->assertTrue(
                BookAccessGrant::query()->where('user_id', $user->id)->where('book_asset_id', $asset->id)->exists(),
            );
        }
    }

    public function test_same_text_different_cover_end_to_end(): void
    {
        $first = $this->submitEpub('epub3');
        $second = $this->submitEpub('epub3DifferentCover');

        $assetA = $first->refresh()->asset;
        $assetB = $second->refresh()->asset;

        // Different files…
        $this->assertNotSame($assetA->sha256, $assetB->sha256);
        // …same normalized text, detected by the REAL fingerprint.
        $this->assertNotNull($assetA->content_sha256);
        $this->assertSame($assetA->content_sha256, $assetB->content_sha256);

        $candidate = DuplicateCandidate::query()->sole();
        $this->assertSame('content_sha256_match', $candidate->reason);
        $this->assertSame('open', $candidate->status->value);

        // Both assets alive, no destructive merge; the edition is shared
        // only because title/creator/language independently corroborate
        // the fingerprint match — labeled high_confidence, never "exact"
        // bibliographic identity, and reversible.
        $this->assertSame(2, BookAsset::query()->count());
        $this->assertSame($assetA->edition_id, $assetB->edition_id);
        $this->assertSame('high_confidence', $assetB->reconciliation['confidence']);
        $this->assertSame(
            'content_fingerprint_with_bibliographic_agreement',
            $assetB->reconciliation['method'],
        );
    }

    public function test_malformed_epub_fails_cleanly(): void
    {
        $submission = $this->submitEpub('malformed');

        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame('validate', $run->current_stage->value);
        $this->assertNotNull($run->last_error_code);
        $this->assertSame('failed', $run->asset->ingestion_status->value);
        $this->assertNull($run->asset->storage_path, 'malformed file must not be promoted');
    }

    public function test_path_traversal_epub_is_hard_blocked(): void
    {
        $submission = $this->submitEpub('pathTraversal');

        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame('ZIP_PATH_TRAVERSAL', $run->last_error_code);
        $this->assertNull($run->asset->storage_path);
        // Nothing escaped into the data root.
        $this->assertFileDoesNotExist(dirname($this->testDataRoot).'/evil.txt');
        $this->assertFileDoesNotExist($this->testDataRoot.'/evil.txt');
    }

    public function test_encrypted_content_needs_review_and_is_not_overrideable(): void
    {
        $submission = $this->submitEpub('encryptedContent');

        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::NeedsReview, $run->status);

        $codes = collect($run->review_issues)->pluck('code');
        $this->assertContains('DRM_ENCRYPTED_CONTENT', $codes);
        $issue = collect($run->review_issues)->firstWhere('code', 'DRM_ENCRYPTED_CONTENT');
        $this->assertFalse($issue['overrideable']);

        // Admin cannot override DRM.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/override', ['code' => 'DRM_ENCRYPTED_CONTENT'])
            ->assertSessionHas('error');
        $this->assertSame(IngestionRunStatus::NeedsReview, $run->refresh()->status);
    }

    public function test_listing_many_submissions_avoids_n_plus_one(): void
    {
        $user = User::factory()->create();
        BookSubmission::factory()->count(120)->create(['user_id' => $user->id]);

        DB::enableQueryLog();
        $response = $this->actingAs($user)->getJson('/api/v1/submissions?per_page=100');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk()->assertJsonCount(100, 'data');
        // Cursor page of 100 rows: submissions + eager latestRun + eager
        // asset + auth/user lookups — far below one query per row.
        $this->assertLessThan(10, count($queries), 'query count suggests N+1: '.count($queries));
    }
}
