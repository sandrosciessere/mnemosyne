<?php

namespace Tests\Feature\Ingestion;

use App\Enums\IngestionRunStatus;
use App\Models\BookSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithFakeWorker;
use Tests\TestCase;

class WarningAndUnsupportedTest extends TestCase
{
    use InteractsWithFakeWorker;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config([
            'mnemosyne.data_path' => Storage::disk('data')->path(''),
            'mnemosyne.worker.internal_token' => 'test-token',
            'mnemosyne.ingestion.queue_connection' => 'database',
            'mnemosyne.ingestion.retry.backoff_seconds' => [0, 0, 0],
        ]);
        $this->admin = User::factory()->admin()->create();
    }

    private function approveAndDrain(): BookSubmission
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/library/submissions', [
            'epub' => UploadedFile::fake()->createWithContent(uniqid().'.epub', 'PK-'.uniqid()),
        ]);
        $submission = BookSubmission::query()->latest('id')->first();
        $this->actingAs($this->admin)->post('/admin/submissions/'.$submission->public_id.'/approve');
        $this->drainQueue();

        return $submission->refresh();
    }

    public function test_clean_run_yields_ready_for_enrichment_without_warning_marker(): void
    {
        $this->fakeHappyWorker();
        $submission = $this->approveAndDrain();

        $asset = $submission->latestRun->asset;
        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $this->assertSame(0, $asset->structure_summary['warnings_count']);
    }

    public function test_recoverable_warnings_yield_visibly_distinct_final_status(): void
    {
        $this->fakeHappyWorker(validateIssues: [[
            'code' => 'XHTML_NOT_WELL_FORMED',
            'severity' => 'warning',
            'message' => 'A spine document required the fallback parser.',
            'overrideable' => false,
        ]]);

        $submission = $this->approveAndDrain();
        $run = $submission->latestRun;
        $asset = $run->asset;

        // The run succeeded, but the book is NOT identical to a clean one.
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        $this->assertSame('ready_for_enrichment_with_warnings', $asset->ingestion_status->value);
        $this->assertTrue($asset->ingestion_status->isReadyForEnrichment());
        $this->assertGreaterThan(0, $asset->structure_summary['warnings_count']);
        $this->assertSame('passed_with_warnings', $asset->validation_status);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'stage.warning']);
    }

    public function test_admin_override_marks_result_as_with_warnings(): void
    {
        // A reviewable overrideable issue blocks the run…
        Http::fake(function ($request) {
            $stage = basename(parse_url($request->url(), PHP_URL_PATH));

            if ($stage === 'validate') {
                return Http::response([
                    'status' => 'needs_review',
                    'stage' => 'validate',
                    'handler_version' => '1.1.0',
                    'duration_ms' => 3,
                    'issues' => [[
                        'code' => 'NAV_MALFORMED',
                        'severity' => 'reviewable',
                        'message' => 'Navigation document is malformed; spine remains readable.',
                        'overrideable' => true,
                    ]],
                    'result' => ['epub_version' => '3.0'],
                ]);
            }

            return Http::response($this->happyEnvelopeFor($stage));
        });

        $submission = $this->approveAndDrain();
        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::NeedsReview, $run->status);

        // …the admin overrides it; the final status must show the scar.
        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/override', ['code' => 'NAV_MALFORMED'])
            ->assertRedirect();
        $this->drainQueue();

        $run->refresh();
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        $this->assertSame(
            'ready_for_enrichment_with_warnings',
            $run->asset->ingestion_status->value,
        );
    }

    public function test_admin_can_mark_failed_run_unsupported(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'failed',
            'stage' => 'validate',
            'handler_version' => '1.1.0',
            'duration_ms' => 2,
            'issues' => [[
                'code' => 'EPUB_OPF_UNREADABLE',
                'severity' => 'hard_block',
                'message' => 'Package document cannot be parsed.',
                'overrideable' => false,
            ]],
            'result' => null,
        ])]);

        $submission = $this->approveAndDrain();
        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Failed, $run->status);

        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/mark-unsupported', [
                'reason' => 'Broken beyond repair; awaiting a corrected file.',
            ])
            ->assertRedirect();

        $run->refresh();
        $this->assertSame(IngestionRunStatus::Skipped, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('unsupported', $run->asset->ingestion_status->value);
        $this->assertSame('unsupported', $submission->refresh()->derivedStatus());

        $event = $run->events()->where('type', 'run.marked_unsupported')->first();
        $this->assertNotNull($event);
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertSame('Broken beyond repair; awaiting a corrected file.', $event->payload['reason']);
    }

    public function test_mark_unsupported_rejected_for_succeeded_runs_and_non_admins(): void
    {
        $this->fakeHappyWorker();
        $submission = $this->approveAndDrain();
        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);

        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/mark-unsupported')
            ->assertSessionHas('error');
        $this->assertSame(IngestionRunStatus::Succeeded, $run->refresh()->status);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->post('/admin/processing/runs/'.$run->public_id.'/mark-unsupported')
            ->assertForbidden();
    }

    public function test_replacement_is_a_new_submission_never_an_overwrite(): void
    {
        // The broken original stays immutable/unsupported; the corrected
        // file arrives as a NEW submission → NEW asset (different sha).
        // Stateful fake: first the worker rejects, then it succeeds
        // (stacked Http::fake registrations would not switch over).
        $workerFixed = false;
        Http::fake(function ($request) use (&$workerFixed) {
            if (! $workerFixed) {
                return Http::response([
                    'status' => 'failed', 'stage' => 'validate', 'handler_version' => '1.1.0',
                    'duration_ms' => 1,
                    'issues' => [['code' => 'ZIP_INVALID', 'severity' => 'hard_block', 'message' => 'not a zip', 'overrideable' => false]],
                    'result' => null,
                ]);
            }

            $stage = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response($this->happyEnvelopeFor($stage));
        });

        $broken = $this->approveAndDrain();
        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$broken->latestRun->public_id.'/mark-unsupported');
        $brokenAsset = $broken->refresh()->asset;

        $workerFixed = true;
        $fixed = $this->approveAndDrain();
        $fixedAsset = $fixed->latestRun->asset;

        $this->assertNotSame($brokenAsset->id, $fixedAsset->id);
        $this->assertNotSame($brokenAsset->sha256, $fixedAsset->sha256);
        $this->assertSame('unsupported', $brokenAsset->refresh()->ingestion_status->value);
        $this->assertSame('ready_for_enrichment', $fixedAsset->ingestion_status->value);
    }
}
