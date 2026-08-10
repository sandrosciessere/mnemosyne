<?php

namespace Tests\Feature\Ingestion;

use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Models\BookAccessGrant;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\DuplicateCandidate;
use App\Models\User;
use App\Models\Work;
use App\Services\Ingestion\StageExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Full pipeline orchestration on the sync queue with a faked worker.
 * The true end-to-end through the real Python worker lives in the
 * E2E/Integration suite; here we verify Laravel's state machine.
 */
class PipelineOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config([
            'mnemosyne.data_path' => Storage::disk('data')->path(''),
            'mnemosyne.worker.internal_token' => 'test-token',
            // Database driver + iterative draining: a sync driver would
            // recurse through the whole stage chain in one call stack.
            'mnemosyne.ingestion.queue_connection' => 'database',
            'mnemosyne.ingestion.retry.backoff_seconds' => [0, 0, 0],
        ]);
    }

    /** Process queued ingestion jobs breadth-first until the queues drain. */
    private function drainIngestionQueue(): void
    {
        for ($iteration = 0; $iteration < 60; $iteration++) {
            $pending = DB::table('jobs')->count();

            if ($pending === 0) {
                return;
            }

            $this->artisan('queue:work', [
                'connection' => 'database',
                '--once' => true,
                '--queue' => 'ingestion-high,ingestion-normal,ingestion-low',
                '--sleep' => 0,
                '--tries' => 1,
            ]);
        }

        $this->fail('Ingestion queue did not drain within 60 jobs.');
    }

    private function fakeWorkerHappyPath(?string $contentSha = null): void
    {
        $contentSha = $contentSha ?? hash('sha256', 'normalized-content');

        Http::fake([
            '*/internal/v1/epub/validate' => Http::response([
                'status' => 'passed',
                'stage' => 'validate',
                'handler_version' => '1.0.0',
                'duration_ms' => 5,
                'issues' => [],
                'result' => [
                    'epub_version' => '3.0',
                    'spine_count' => 3,
                    'zip' => ['entry_count' => 8, 'total_uncompressed_bytes' => 4096],
                ],
            ]),
            '*/internal/v1/epub/parse' => Http::response([
                'status' => 'passed',
                'stage' => 'parse',
                'handler_version' => '1.0.0',
                'duration_ms' => 9,
                'issues' => [],
                'result' => [
                    'metadata' => [
                        'title' => 'Synthetic Test Book',
                        'subtitle' => null,
                        'creators' => [
                            ['name' => 'Ada Example', 'roles' => ['aut'], 'file_as' => 'Example, Ada', 'lang' => null],
                            ['name' => 'Turing Translator', 'roles' => ['trl'], 'file_as' => null, 'lang' => null],
                        ],
                        'contributors' => [],
                        'languages' => ['en'],
                        'identifiers' => [
                            ['scheme' => 'isbn13', 'value' => '9780316769488', 'raw' => 'urn:isbn:9780316769488', 'valid' => true],
                        ],
                        'publisher' => 'Mnemosyne Test Press',
                        'dates' => [['value' => '2024-01-15', 'event' => null]],
                        'description' => 'A synthetic book.',
                        'subjects' => ['Testing'],
                        'rights' => null,
                    ],
                ],
            ]),
            '*/internal/v1/epub/normalize' => Http::response([
                'status' => 'passed',
                'stage' => 'normalize',
                'handler_version' => '1.0.0',
                'duration_ms' => 12,
                'issues' => [],
                'result' => ['spine_documents' => 3, 'nodes' => 42, 'chars' => 9000, 'image_only_documents' => 0],
            ]),
            '*/internal/v1/epub/structure' => Http::response([
                'status' => 'passed',
                'stage' => 'structure',
                'handler_version' => '1.0.0',
                'duration_ms' => 7,
                'issues' => [],
                'result' => [
                    'content_sha256' => $contentSha,
                    'fingerprint_version' => '1',
                    'counts' => ['spine_documents' => 3, 'nodes' => 42, 'chars' => 9000, 'sections' => 5, 'toc_entries' => 4],
                ],
            ]),
        ]);
    }

    private function submitAndApprove(?User $user = null, string $content = 'PK-synthetic-epub-bytes'): BookSubmission
    {
        $user = $user ?? User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($user)->post('/library/submissions', [
            'epub' => UploadedFile::fake()->createWithContent('test.epub', $content),
        ]);

        $submission = BookSubmission::query()->where('user_id', $user->id)->latest('id')->first();

        $this->actingAs($admin)->post('/admin/submissions/'.$submission->public_id.'/approve');
        $this->drainIngestionQueue();

        return $submission->refresh();
    }

    public function test_full_pipeline_reaches_ready_for_enrichment(): void
    {
        $this->fakeWorkerHappyPath();
        $user = User::factory()->create();

        $submission = $this->submitAndApprove($user);

        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        $this->assertSame(100, $run->progress);
        $this->assertNotNull($run->finished_at);

        $asset = $run->asset;
        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $this->assertSame('passed', $asset->validation_status);
        $this->assertSame('3.0', $asset->epub_version);
        $this->assertSame('1', $asset->pipeline_version);
        $this->assertSame(hash('sha256', 'PK-synthetic-epub-bytes'), $asset->sha256);
        $this->assertNotNull($asset->content_sha256);

        // Content-addressed original exists; incoming cleaned up.
        Storage::disk('data')->assertExists(BookAsset::originalStoragePath($asset->sha256));
        $this->assertNull($submission->refresh()->incoming_path);
        Storage::disk('data')->assertMissing('library/incoming/'.$submission->public_id.'/source.epub');

        // Bibliographic reconciliation: provisional Work/Edition + people.
        $this->assertSame('Synthetic Test Book', $asset->edition->title);
        $this->assertSame('Mnemosyne Test Press', $asset->edition->publisher);
        $this->assertSame('en', $asset->edition->language);
        $this->assertSame(2024, $asset->edition->publication_year);
        $this->assertSame('provisional', $asset->edition->status->value);
        $this->assertSame('Synthetic Test Book', $asset->edition->work->canonical_title);
        $this->assertSame(['aut', 'trl'], $asset->edition->contributors->pluck('pivot.role')->all());
        $this->assertSame('isbn13', $asset->edition->identifiers->first()->scheme);
        $this->assertSame('unresolved', $asset->reconciliation['confidence']);

        // Submitter got access.
        $this->assertTrue(
            BookAccessGrant::query()->where('user_id', $user->id)->where('book_asset_id', $asset->id)->exists(),
        );

        // Attempts: one per stage, all succeeded, with versions + durations.
        $attempts = $run->attempts;
        $this->assertSame(['hash', 'validate', 'parse', 'normalize', 'structure'], $attempts->pluck('stage.value')->all());
        $this->assertSame(['succeeded'], $attempts->pluck('status')->unique()->all());
        $this->assertContainsOnly('int', $attempts->pluck('duration_ms')->all());

        // Timeline tells the whole story.
        $types = $run->events->pluck('type')->all();
        foreach ([
            'run.queued', 'run.started', 'stage.started', 'stage.completed',
            'asset.promoted_to_original', 'asset.reconciled', 'run.succeeded',
        ] as $expected) {
            $this->assertContains($expected, $types);
        }
    }

    public function test_exact_duplicate_reuses_asset_and_file(): void
    {
        $this->fakeWorkerHappyPath();

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->submitAndApprove($firstUser, 'identical-bytes');
        $second = $this->submitAndApprove($secondUser, 'identical-bytes');

        $this->assertSame(1, BookAsset::query()->count());
        $asset = BookAsset::query()->sole();

        $second->refresh();
        $this->assertTrue($second->is_exact_duplicate);
        $this->assertSame($asset->id, $second->book_asset_id);
        $this->assertSame(IngestionRunStatus::Succeeded, $second->latestRun->status);

        // Only ONE physical original file.
        $files = Storage::disk('data')->allFiles('library/original');
        $this->assertCount(1, $files);

        // Both submitters have access; provenance kept via two submissions.
        $this->assertSame(2, $asset->submissions()->count());
        foreach ([$firstUser, $secondUser] as $user) {
            $this->assertTrue(
                BookAccessGrant::query()->where('user_id', $user->id)->where('book_asset_id', $asset->id)->exists(),
            );
        }

        $this->assertDatabaseHas('ingestion_events', ['type' => 'asset.duplicate_exact']);

        // No second parse of the same file: the duplicate run has only a
        // hash attempt.
        $this->assertSame(['hash'], $second->latestRun->attempts->pluck('stage.value')->all());
    }

    public function test_same_content_different_file_creates_candidate_not_merge(): void
    {
        $sharedContentSha = hash('sha256', 'same-normalized-text');
        $this->fakeWorkerHappyPath($sharedContentSha);

        $first = $this->submitAndApprove(content: 'epub-with-red-cover');
        $second = $this->submitAndApprove(content: 'epub-with-blue-cover');

        $this->assertSame(2, BookAsset::query()->count());
        $assetA = $first->refresh()->asset;
        $assetB = $second->refresh()->asset;

        $this->assertNotSame($assetA->sha256, $assetB->sha256);
        $this->assertSame($assetA->content_sha256, $assetB->content_sha256);

        $candidate = DuplicateCandidate::query()->sole();
        $this->assertSame('content_sha256_match', $candidate->reason);
        $this->assertSame('open', $candidate->status->value);
        $this->assertNotNull($candidate->evidence['metadata_comparison']);

        // Both assets survive — no destructive merge. The edition is
        // shared only because the bibliographic metadata (title, creator,
        // language) independently corroborates the fingerprint match —
        // and it is labeled high_confidence, never bibliographic "exact".
        $this->assertSame('ready_for_enrichment', $assetA->ingestion_status->value);
        $this->assertSame('ready_for_enrichment', $assetB->ingestion_status->value);
        $this->assertSame($assetA->edition_id, $assetB->edition_id);
        $this->assertSame('high_confidence', $assetB->reconciliation['confidence']);
        $this->assertSame('content_fingerprint_with_bibliographic_agreement', $assetB->reconciliation['method']);
        $this->assertSame(1, Work::query()->count());
        $this->assertDatabaseHas('ingestion_events', ['type' => 'asset.duplicate_candidate']);
    }

    public function test_same_content_with_conflicting_metadata_does_not_share_edition(): void
    {
        // Identical normalized text but the OPF metadata names a different
        // book: the fingerprint alone must NOT establish edition identity.
        $sharedContentSha = hash('sha256', 'same-text-conflicting-meta');
        $call = 0;

        Http::fake(function ($request) use (&$call, $sharedContentSha) {
            $stage = basename(parse_url($request->url(), PHP_URL_PATH));

            if ($stage === 'parse') {
                $call++;
                $meta = $call === 1
                    ? ['title' => 'First Title', 'creators' => [['name' => 'Alice Author', 'roles' => ['aut']]]]
                    : ['title' => 'Completely Different Title', 'creators' => [['name' => 'Bob Other', 'roles' => ['aut']]]];

                return Http::response($this->passedEnvelope('parse', [
                    'metadata' => $meta + ['languages' => ['en'], 'identifiers' => []],
                ]));
            }

            if ($stage === 'structure') {
                return Http::response($this->passedEnvelope('structure', [
                    'content_sha256' => $sharedContentSha,
                    'fingerprint_version' => '1',
                    'counts' => ['sections' => 1, 'toc_entries' => 0, 'nodes' => 3, 'chars' => 90],
                ]));
            }

            return Http::response($this->happyEnvelopeFor($stage));
        });

        $first = $this->submitAndApprove(content: 'conflict-bytes-one');
        $second = $this->submitAndApprove(content: 'conflict-bytes-two');

        $assetA = $first->refresh()->asset;
        $assetB = $second->refresh()->asset;

        $this->assertSame($assetA->content_sha256, $assetB->content_sha256);
        // Evidence recorded for the admin…
        $this->assertSame(1, DuplicateCandidate::query()->count());
        // …but NO silent identity: two provisional editions/works.
        $this->assertNotSame($assetA->edition_id, $assetB->edition_id);
        $this->assertSame(2, Work::query()->count());
        $this->assertSame('unresolved', $assetB->reconciliation['confidence']);
    }

    public function test_reviewable_issue_pauses_run_and_override_resumes_it(): void
    {
        Http::fake([
            '*/internal/v1/epub/validate' => Http::response([
                'status' => 'needs_review',
                'stage' => 'validate',
                'handler_version' => '1.0.0',
                'duration_ms' => 3,
                'issues' => [[
                    'code' => 'DRM_ENCRYPTED_CONTENT',
                    'severity' => 'reviewable',
                    'message' => 'Content resources are listed in encryption.xml.',
                    'overrideable' => true,
                ]],
                'result' => ['epub_version' => '2.0'],
            ]),
            '*/internal/v1/epub/parse' => Http::response($this->passedEnvelope('parse', ['metadata' => ['title' => 'Reviewed Book', 'creators' => [], 'languages' => ['en'], 'identifiers' => []]])),
            '*/internal/v1/epub/normalize' => Http::response($this->passedEnvelope('normalize', ['spine_documents' => 1, 'nodes' => 2, 'chars' => 50])),
            '*/internal/v1/epub/structure' => Http::response($this->passedEnvelope('structure', ['content_sha256' => hash('sha256', 'x'), 'fingerprint_version' => '1', 'counts' => ['sections' => 1, 'toc_entries' => 0]])),
        ]);

        $submission = $this->submitAndApprove();
        $run = $submission->latestRun;

        $this->assertSame(IngestionRunStatus::NeedsReview, $run->status);
        $this->assertSame('validate', $run->current_stage->value);
        $this->assertSame('DRM_ENCRYPTED_CONTENT', $run->review_issues[0]['code']);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.needs_review']);

        // Non-admin cannot override.
        $user = User::factory()->create();
        $this->actingAs($user)
            ->post('/admin/processing/runs/'.$run->public_id.'/override', ['code' => 'DRM_ENCRYPTED_CONTENT'])
            ->assertForbidden();

        // Admin override resumes and completes the pipeline.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/override', ['code' => 'DRM_ENCRYPTED_CONTENT'])
            ->assertRedirect();
        $this->drainIngestionQueue();

        $run->refresh();
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        $this->assertContains('DRM_ENCRYPTED_CONTENT', $run->overridden_issues);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.issue_overridden']);
    }

    public function test_hard_security_block_fails_and_cannot_be_overridden(): void
    {
        Http::fake([
            '*/internal/v1/epub/validate' => Http::response([
                'status' => 'failed',
                'stage' => 'validate',
                'handler_version' => '1.0.0',
                'duration_ms' => 2,
                'issues' => [[
                    'code' => 'ZIP_PATH_TRAVERSAL',
                    'severity' => 'hard_block',
                    'message' => 'Archive entry escapes the extraction root.',
                    'overrideable' => false,
                ]],
                'result' => null,
            ]),
        ]);

        $submission = $this->submitAndApprove();
        $run = $submission->latestRun;

        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame('ZIP_PATH_TRAVERSAL', $run->last_error_code);
        $this->assertSame('failed', $run->asset->ingestion_status->value);

        // The malicious file is NOT promoted to original storage.
        $this->assertNull($run->asset->storage_path);
        $this->assertCount(0, Storage::disk('data')->allFiles('library/original'));

        // Override attempt must fail (not in review, and never overrideable).
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/override', ['code' => 'ZIP_PATH_TRAVERSAL'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(IngestionRunStatus::Failed, $run->refresh()->status);
    }

    public function test_worker_outage_retries_then_fails_with_attempts_recorded(): void
    {
        config(['mnemosyne.ingestion.retry.backoff_seconds' => [0, 0, 0]]);
        Http::fake(['*/internal/v1/epub/*' => Http::response('worker down', 500)]);

        $submission = $this->submitAndApprove();
        $run = $submission->latestRun;

        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame('WORKER_UNAVAILABLE', $run->last_error_code);

        $validateAttempts = $run->attempts->where('stage.value', 'validate');
        $this->assertCount(3, $validateAttempts); // max_attempts_per_stage
        $this->assertSame([1, 2, 3], $validateAttempts->pluck('attempt')->all());
    }

    public function test_admin_retry_resumes_from_failed_stage(): void
    {
        config(['mnemosyne.ingestion.retry.max_attempts_per_stage' => 1]);

        // Stateful fake: worker is down until we flip the flag (stacked
        // Http::fake patterns would keep matching the first registration).
        $workerUp = false;
        Http::fake(function ($request) use (&$workerUp) {
            if (! $workerUp) {
                return Http::response('worker down', 500);
            }

            $stage = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response($this->happyEnvelopeFor($stage));
        });

        $submission = $this->submitAndApprove();
        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame('validate', $run->current_stage->value);
        $hashAttempts = $run->attempts()->where('stage', 'hash')->count();

        // Worker recovers; admin retries.
        $workerUp = true;
        config(['mnemosyne.ingestion.retry.max_attempts_per_stage' => 3]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/retry')
            ->assertRedirect();
        $this->drainIngestionQueue();

        $run->refresh();
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        // Hash was NOT redone: the retry resumed from validate.
        $this->assertSame($hashAttempts, $run->attempts()->where('stage', 'hash')->count());
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.retry_requested']);
    }

    public function test_cooperative_cancellation_before_stage_start(): void
    {
        $this->fakeWorkerHappyPath();

        // Keep the job unexecuted: fake queue for the approval dispatch.
        Queue::fake();
        $submission = $this->submitAndApprove();
        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Queued, $run->status);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/cancel')
            ->assertRedirect();

        $this->assertTrue($run->refresh()->cancel_requested);

        // The stage job wakes up and honors the flag instead of running.
        app(StageExecutor::class)
            ->execute($run, IngestionStage::Hash);

        $run->refresh();
        $this->assertSame(IngestionRunStatus::Cancelled, $run->status);
        $this->assertSame(0, $run->attempts()->count());
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.cancelled']);
    }

    public function test_priority_change_is_audited_and_applied(): void
    {
        $this->fakeWorkerHappyPath();
        Queue::fake();

        $submission = $this->submitAndApprove();
        $run = $submission->latestRun;

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->patch('/admin/processing/runs/'.$run->public_id.'/priority', ['priority' => 'high'])
            ->assertRedirect();

        $this->assertSame('high', $run->refresh()->priority->value);
        $this->assertSame('high', $submission->refresh()->priority->value);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.priority_changed']);
    }

    private function happyEnvelopeFor(string $stage): array
    {
        return match ($stage) {
            'validate' => $this->passedEnvelope('validate', [
                'epub_version' => '3.0', 'uncompressed_size' => 4096, 'entry_count' => 8, 'spine_count' => 3,
            ]),
            'parse' => $this->passedEnvelope('parse', [
                'metadata' => [
                    'title' => 'Retried Book',
                    'creators' => [['name' => 'Ada Example', 'roles' => ['aut']]],
                    'languages' => ['en'],
                    'identifiers' => [],
                ],
            ]),
            'normalize' => $this->passedEnvelope('normalize', ['spine_documents' => 3, 'nodes' => 10, 'chars' => 500]),
            'structure' => $this->passedEnvelope('structure', [
                'content_sha256' => hash('sha256', 'retried'),
                'fingerprint_version' => '1',
                'counts' => ['sections' => 2, 'toc_entries' => 2, 'nodes' => 10, 'chars' => 500],
            ]),
            default => $this->passedEnvelope($stage, []),
        };
    }

    private function passedEnvelope(string $stage, array $result): array
    {
        return [
            'status' => 'passed',
            'stage' => $stage,
            'handler_version' => '1.0.0',
            'duration_ms' => 4,
            'issues' => [],
            'result' => $result,
        ];
    }
}
