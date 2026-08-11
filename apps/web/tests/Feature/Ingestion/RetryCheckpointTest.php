<?php

namespace Tests\Feature\Ingestion;

use App\Enums\AssetIngestionStatus;
use App\Enums\IngestionRunStatus;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithFakeWorker;
use Tests\TestCase;

/**
 * Admin retry must resume from the DURABLE checkpoint (nextDispatchStage),
 * never a raw current_stage. Otherwise a run whose current stage already has
 * a succeeded attempt (a mirrored-failed duplicate, or a crash between an
 * attempt succeeding and the current_stage advance) would dispatch a stage
 * the executor immediately discards as superseded — wedging it in `queued`
 * with no job.
 */
class RetryCheckpointTest extends TestCase
{
    use InteractsWithFakeWorker;
    use RefreshDatabase;

    private User $admin;

    /** Mutable worker behaviour: when true, validate fails deterministically. */
    private object $worker;

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

        // A single fake whose validate behaviour is switchable at runtime, so
        // a producer can fail at validate and later recover in the same test
        // (re-calling Http::fake only appends stubs — first match wins).
        $this->worker = (object) ['failValidate' => true];
        Http::fake(function ($request) {
            $stage = basename(parse_url($request->url(), PHP_URL_PATH));

            if ($stage === 'validate' && $this->worker->failValidate) {
                return Http::response([
                    'status' => 'failed', 'stage' => 'validate', 'handler_version' => '1.0.0', 'duration_ms' => 2,
                    'issues' => [['code' => 'EPUB_MALFORMED', 'severity' => 'reviewable', 'message' => 'bad', 'overrideable' => false]],
                    'result' => [],
                ]);
            }

            return Http::response($this->happyEnvelopeFor($stage));
        });
    }

    private function submitApprove(?User $user, string $content): BookSubmission
    {
        $user = $user ?? User::factory()->create();
        $this->actingAs($user)->post('/library/submissions', [
            'epub' => UploadedFile::fake()->createWithContent(uniqid().'.epub', $content),
        ]);
        $submission = BookSubmission::query()->where('user_id', $user->id)->latest('id')->first();
        $this->actingAs($this->admin)->post('/admin/submissions/'.$submission->public_id.'/approve');

        return $submission->refresh();
    }

    // F3 — retrying an exact-duplicate that mirrored a FAILED asset must not
    // be silently absorbed: Hash already succeeded, so retry must resume at
    // validate (the durable checkpoint) and drive the run forward.
    public function test_retry_of_mirrored_failed_duplicate_resumes_at_checkpoint(): void
    {
        // Producer fails at validate → asset Failed.
        $first = $this->submitApprove(null, 'retry-dup-bytes');
        $this->drainQueue();
        $asset = $first->refresh()->asset;
        $this->assertSame(AssetIngestionStatus::Failed, $asset->ingestion_status);

        // Exact duplicate mirrors the failure (Hash succeeded, no reprocess).
        $userB = User::factory()->create();
        $second = $this->submitApprove($userB, 'retry-dup-bytes');
        $this->drainQueue();
        $runB = $second->refresh()->latestRun;
        $this->assertSame(IngestionRunStatus::Failed, $runB->status);
        $this->assertSame('hash', $runB->current_stage->value);
        $this->assertSame(['hash'], $runB->attempts->pluck('stage.value')->all());

        // The worker recovers; admin retries the duplicate.
        $this->worker->failValidate = false;
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$runB->public_id.'/retry');

        // Retry resumes at validate, NOT hash (which would be discarded).
        $retryEvent = $runB->events()->where('type', 'run.retry_requested')->latest('id')->first();
        $this->assertSame('validate', $retryEvent->payload['stage']);

        $this->drainQueue();
        $runB->refresh();

        // Forward progress to success; Hash is not re-executed; no wedge.
        $this->assertSame(IngestionRunStatus::Succeeded, $runB->status);
        $this->assertSame(1, $runB->attempts()->where('stage', 'hash')->count(), 'Hash must not re-run.');
        $this->assertContains('validate', $runB->attempts()->pluck('stage')->map->value->all());
        $this->assertSame(AssetIngestionStatus::ReadyForEnrichment, $asset->refresh()->ingestion_status);
        $this->assertSame(1, BookAsset::query()->count());
    }

    // F3 (control) — an ordinary run that failed AT a stage (no succeeded
    // attempt for it) still re-runs that same stage on retry.
    public function test_ordinary_retry_reruns_the_failed_stage(): void
    {
        $submission = $this->submitApprove(null, 'ordinary-retry-bytes');
        $this->drainQueue();
        $run = $submission->refresh()->latestRun;
        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame('validate', $run->current_stage->value);

        $validateAttemptsBefore = $run->attempts()->where('stage', 'validate')->count();

        // validate has a FAILED attempt (not succeeded) → checkpoint is validate.
        $this->worker->failValidate = false;
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$run->public_id.'/retry');

        $retryEvent = $run->events()->where('type', 'run.retry_requested')->latest('id')->first();
        $this->assertSame('validate', $retryEvent->payload['stage']);

        $this->drainQueue();
        $run->refresh();

        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        // validate was retried: at least one more validate attempt than before.
        $this->assertGreaterThan(
            $validateAttemptsBefore,
            $run->attempts()->where('stage', 'validate')->count(),
        );
    }
}
