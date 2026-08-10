<?php

namespace Tests\Feature\Ingestion;

use App\Enums\AssetIngestionStatus;
use App\Enums\IngestionRunStatus;
use App\Models\BookAccessGrant;
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
 * Exact-duplicate handling must never claim a success the original did not
 * earn, never reverse an admin unsupported decision, and never crash or
 * create a second active run when the original is still in flight or paused.
 */
class DuplicateDispositionTest extends TestCase
{
    use InteractsWithFakeWorker;
    use RefreshDatabase;

    private User $admin;

    private const CONTENT = 'PK-identical-duplicate-bytes';

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

    public function test_duplicate_of_ready_with_warnings_asset_succeeds_without_reprocessing(): void
    {
        // Original ingests with a recoverable warning → ready_with_warnings.
        $this->fakeHappyWorker([[
            'code' => 'HTML_FALLBACK_PARSER', 'severity' => 'warning',
            'message' => 'recoverable', 'overrideable' => false,
        ]]);
        $first = $this->submitApprove();
        $this->drainQueue();
        $asset = $first->refresh()->asset;
        $this->assertSame(AssetIngestionStatus::ReadyForEnrichmentWithWarnings, $asset->ingestion_status);

        // Duplicate: succeeds, grants access, never reprocesses, and does NOT
        // downgrade the asset's with-warnings status.
        $secondUser = User::factory()->create();
        $second = $this->submitApprove($secondUser);
        $this->drainQueue();

        $this->assertSame(1, BookAsset::query()->count());
        $this->assertSame(IngestionRunStatus::Succeeded, $second->refresh()->latestRun->status);
        $this->assertSame(['hash'], $second->latestRun->attempts->pluck('stage.value')->all());
        $this->assertSame(AssetIngestionStatus::ReadyForEnrichmentWithWarnings, $asset->refresh()->ingestion_status);
        $this->assertTrue(
            BookAccessGrant::query()->where('user_id', $secondUser->id)->where('book_asset_id', $asset->id)->exists(),
        );
    }

    public function test_duplicate_of_unsupported_asset_is_not_silently_reprocessed(): void
    {
        $this->fakeHappyWorker();
        $first = $this->submitApprove();
        $this->workOneJob(); // hash only → asset exists, run at validate
        $run = $first->refresh()->latestRun;
        $asset = $first->refresh()->asset;
        $this->assertSame(AssetIngestionStatus::Processing, $asset->ingestion_status);

        // Admin pauses then marks the book unsupported (audited decision).
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$run->public_id.'/pause');
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$run->public_id.'/mark-unsupported');
        $this->assertSame(AssetIngestionStatus::Unsupported, $asset->refresh()->ingestion_status);

        // Duplicate submission must mirror the decision, never reprocess.
        $secondUser = User::factory()->create();
        $second = $this->submitApprove($secondUser);
        $this->drainQueue();

        $this->assertSame(1, BookAsset::query()->count());
        $this->assertSame(AssetIngestionStatus::Unsupported, $asset->refresh()->ingestion_status);
        $this->assertSame(IngestionRunStatus::Skipped, $second->refresh()->latestRun->status);
        $this->assertFalse(
            BookAccessGrant::query()->where('user_id', $secondUser->id)->where('book_asset_id', $asset->id)->exists(),
        );
    }

    public function test_duplicate_while_original_is_paused_waits_then_mirrors_success(): void
    {
        $this->fakeHappyWorker();
        $first = $this->submitApprove();
        $this->workOneJob(); // hash → asset created, original now at validate
        $run = $first->refresh()->latestRun;
        $asset = $first->refresh()->asset;

        // Pause the original mid-pipeline (its asset is still `processing`).
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$run->public_id.'/pause');
        $this->assertSame(IngestionRunStatus::Paused, $run->refresh()->status);

        // Duplicate arrives while the owner is in flight: it must park
        // waiting, never create a second active run on the asset, never
        // claim success.
        $secondUser = User::factory()->create();
        $second = $this->submitApprove($secondUser);
        $this->drainQueue();

        $secondRun = $second->refresh()->latestRun;
        $this->assertSame(IngestionRunStatus::Queued, $secondRun->status);
        $this->assertSame($asset->id, $secondRun->waiting_on_asset_id);
        $this->assertNull($secondRun->book_asset_id);
        $this->assertFalse(
            BookAccessGrant::query()->where('user_id', $secondUser->id)->exists(),
        );
        $this->assertSame(1, BookAsset::query()->count());

        // Resume the original: it completes, and the waiting duplicate is
        // finalized to mirror the ready outcome, with a grant, no reprocess.
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$run->public_id.'/resume');
        $this->drainQueue();

        $this->assertSame(IngestionRunStatus::Succeeded, $run->refresh()->status);
        $secondRun->refresh();
        $this->assertSame(IngestionRunStatus::Succeeded, $secondRun->status);
        $this->assertNull($secondRun->waiting_on_asset_id);
        $this->assertSame($asset->id, $secondRun->book_asset_id);
        $this->assertSame(['hash'], $secondRun->attempts->pluck('stage.value')->all());
        $this->assertTrue(
            BookAccessGrant::query()->where('user_id', $secondUser->id)->where('book_asset_id', $asset->id)->exists(),
        );
    }

    public function test_duplicate_of_failed_asset_mirrors_failure_not_success(): void
    {
        // Original fails at validate (deterministic worker failure).
        Http::fake([
            '*/internal/v1/epub/validate' => Http::response([
                'status' => 'failed', 'stage' => 'validate', 'handler_version' => '1.0.0',
                'duration_ms' => 3,
                'issues' => [['code' => 'EPUB_MALFORMED', 'severity' => 'reviewable', 'message' => 'bad', 'overrideable' => false]],
                'result' => [],
            ]),
        ]);
        $first = $this->submitApprove();
        $this->drainQueue();
        $asset = $first->refresh()->asset;
        $this->assertSame(AssetIngestionStatus::Failed, $asset->ingestion_status);

        // Duplicate mirrors the failure — it must not appear successful.
        $secondUser = User::factory()->create();
        $second = $this->submitApprove($secondUser);
        $this->drainQueue();

        $this->assertSame(1, BookAsset::query()->count());
        $this->assertSame(IngestionRunStatus::Failed, $second->refresh()->latestRun->status);
        $this->assertFalse(
            BookAccessGrant::query()->where('user_id', $secondUser->id)->exists(),
        );
    }
}
