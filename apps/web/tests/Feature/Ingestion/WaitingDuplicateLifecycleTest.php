<?php

namespace Tests\Feature\Ingestion;

use App\Enums\AssetIngestionStatus;
use App\Enums\IngestionRunStatus;
use App\Models\BookAccessGrant;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use App\Models\IngestionStageAttempt;
use App\Models\User;
use App\Services\Ingestion\RunStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithFakeWorker;
use Tests\TestCase;

/**
 * A run parked waiting on another run's asset must ALWAYS be resolved when
 * that producing run reaches a terminal state — including cancellation, the
 * one terminal transition that cannot be mirrored (the asset earned no
 * outcome). A cancelled producer promotes a waiter to continue the orphaned
 * asset; no waiter is ever left queued forever. Parking is also guarded so a
 * finalize that wins the race cannot be overwritten back to waiting.
 */
class WaitingDuplicateLifecycleTest extends TestCase
{
    use InteractsWithFakeWorker;
    use RefreshDatabase;

    private User $admin;

    private const CONTENT = 'PK-waiting-lifecycle-bytes';

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

    private function submitApprove(?User $user = null, string $content = self::CONTENT): BookSubmission
    {
        $user = $user ?? User::factory()->create();
        $this->actingAs($user)->post('/library/submissions', [
            'epub' => UploadedFile::fake()->createWithContent(uniqid().'.epub', $content),
        ]);
        $submission = BookSubmission::query()->where('user_id', $user->id)->latest('id')->first();
        $this->actingAs($this->admin)->post('/admin/submissions/'.$submission->public_id.'/approve');

        return $submission->refresh();
    }

    // F1 — cancelling the producing run must not strand its single waiter.
    public function test_cancelling_producer_promotes_the_single_waiter_to_completion(): void
    {
        $this->fakeHappyWorker();

        // Producer A: hash only, then held mid-pipeline via pause (its asset
        // is `processing`, so it still owns the pipeline).
        $a = $this->submitApprove();
        $this->workOneJob();
        $runA = $a->refresh()->latestRun;
        $asset = $a->refresh()->asset;
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$runA->public_id.'/pause');

        // Duplicate B parks waiting on A's asset.
        $userB = User::factory()->create();
        $b = $this->submitApprove($userB);
        $this->drainQueue();
        $runB = $b->refresh()->latestRun;
        $this->assertSame(IngestionRunStatus::Queued, $runB->status);
        $this->assertSame($asset->id, $runB->waiting_on_asset_id);
        $this->assertNull($runB->book_asset_id);

        // Cancel the producer. B must be promoted and driven to completion.
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$runA->public_id.'/cancel');
        $this->drainQueue();

        $runA->refresh();
        $runB->refresh();
        $this->assertSame(IngestionRunStatus::Cancelled, $runA->status);

        // No waiter left parked on a terminal producer.
        $this->assertSame(
            0,
            IngestionRun::query()->whereNotNull('waiting_on_asset_id')
                ->whereIn('status', ['queued', 'running', 'paused', 'needs_review'])->count(),
        );

        // B adopted the asset and completed it — one physical asset, ready.
        $this->assertNull($runB->waiting_on_asset_id);
        $this->assertSame($asset->id, $runB->book_asset_id);
        $this->assertSame(IngestionRunStatus::Succeeded, $runB->status);
        $this->assertSame(1, BookAsset::query()->count());
        $this->assertSame(AssetIngestionStatus::ReadyForEnrichment, $asset->refresh()->ingestion_status);
        $this->assertTrue(
            BookAccessGrant::query()->where('user_id', $userB->id)->where('book_asset_id', $asset->id)->exists(),
        );
    }

    // F1 — with MULTIPLE waiters, one is promoted and the rest are resolved
    // by that new producer's finalization. None is orphaned.
    public function test_cancelling_producer_resolves_all_waiters(): void
    {
        $this->fakeHappyWorker();

        $a = $this->submitApprove();
        $this->workOneJob();
        $runA = $a->refresh()->latestRun;
        $asset = $a->refresh()->asset;
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$runA->public_id.'/pause');

        $userB = User::factory()->create();
        $userC = User::factory()->create();
        $b = $this->submitApprove($userB);
        $this->drainQueue();
        $c = $this->submitApprove($userC);
        $this->drainQueue();

        $this->assertSame($asset->id, $b->refresh()->latestRun->waiting_on_asset_id);
        $this->assertSame($asset->id, $c->refresh()->latestRun->waiting_on_asset_id);

        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$runA->public_id.'/cancel');
        $this->drainQueue();

        // No waiter orphaned; both submissions reach a terminal success.
        $this->assertSame(
            0,
            IngestionRun::query()->whereNotNull('waiting_on_asset_id')
                ->whereIn('status', ['queued', 'running', 'paused', 'needs_review'])->count(),
        );
        $this->assertSame(IngestionRunStatus::Succeeded, $b->refresh()->latestRun->status);
        $this->assertSame(IngestionRunStatus::Succeeded, $c->refresh()->latestRun->status);
        $this->assertSame(1, BookAsset::query()->count());
        $this->assertSame($asset->id, $b->refresh()->latestRun->book_asset_id);
        $this->assertSame($asset->id, $c->refresh()->latestRun->book_asset_id);
        $this->assertTrue(BookAccessGrant::query()->where('user_id', $userB->id)->exists());
        $this->assertTrue(BookAccessGrant::query()->where('user_id', $userC->id)->exists());
    }

    // F2 — a producer finalize that wins the race must not be overwritten by a
    // later (stale) park attempt: the waiter stays terminal.
    public function test_finalize_wins_then_stale_park_cannot_resurrect_waiter(): void
    {
        $asset = BookAsset::factory()->readyForEnrichment()->create();
        $waiter = $this->makeWaiter($asset);

        $stateMachine = app(RunStateMachine::class);

        // Producer finished first: the waiter was finalized (mirrored the
        // ready asset → succeeded, waiting_on_asset_id cleared, asset linked).
        $waiter->forceFill(['book_asset_id' => $asset->id])->save();
        $waiter->setRelation('asset', $asset);
        $stateMachine->mirrorDuplicateOutcome($waiter);
        $this->assertSame(IngestionRunStatus::Succeeded, $waiter->refresh()->status);

        // A stale park attempt now arrives (loser of the race), still holding
        // the pre-finalize view. It must be a no-op — never resurrect to queued.
        $stale = IngestionRun::query()->find($waiter->id);
        $stale->forceFill(['status' => IngestionRunStatus::Running, 'waiting_on_asset_id' => $asset->id]);
        $stateMachine->parkWaitingDuplicate($stale);

        $this->assertSame(IngestionRunStatus::Succeeded, $waiter->refresh()->status);
        $this->assertNull($waiter->waiting_on_asset_id);
    }

    // F2 — the inverse ordering: park wins first, then the producer's
    // finalization still resolves the parked waiter coherently.
    public function test_park_wins_then_finalize_resolves_waiter(): void
    {
        $asset = BookAsset::factory()->readyForEnrichment()->create();
        $waiter = $this->makeWaiter($asset, IngestionRunStatus::Running);

        $stateMachine = app(RunStateMachine::class);

        // Park wins: waiter → queued, still waiting on the asset.
        $stateMachine->parkWaitingDuplicate($waiter);
        $this->assertSame(IngestionRunStatus::Queued, $waiter->refresh()->status);
        $this->assertSame($asset->id, $waiter->waiting_on_asset_id);

        // The producer finalizes (ready): finalizeWaitingDuplicates runs from
        // a terminal transition on the asset — mark a companion producer run
        // succeeded to trigger it.
        $producer = $this->makeProducer($asset);
        $stateMachine->markSucceeded($producer);

        $waiter->refresh();
        $this->assertSame(IngestionRunStatus::Succeeded, $waiter->status);
        $this->assertNull($waiter->waiting_on_asset_id);
        $this->assertSame($asset->id, $waiter->book_asset_id);
    }

    private function makeWaiter(BookAsset $asset, IngestionRunStatus $status = IngestionRunStatus::Running): IngestionRun
    {
        $submission = BookSubmission::factory()->approved()->create(['is_exact_duplicate' => true]);
        $run = IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => $status,
            'current_stage' => 'hash',
            'waiting_on_asset_id' => $asset->id,
            'book_asset_id' => null,
        ]);
        // A waiter always has a succeeded hash attempt behind it.
        $attempt = new IngestionStageAttempt;
        $attempt->forceFill([
            'ingestion_run_id' => $run->id,
            'stage' => 'hash', 'attempt' => 1, 'status' => 'succeeded',
            'started_at' => now(), 'finished_at' => now(),
        ])->save();

        return $run;
    }

    private function makeProducer(BookAsset $asset): IngestionRun
    {
        $submission = BookSubmission::factory()->approved()->create();

        return IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => IngestionRunStatus::Running,
            'current_stage' => 'structure',
            'book_asset_id' => $asset->id,
        ]);
    }
}
