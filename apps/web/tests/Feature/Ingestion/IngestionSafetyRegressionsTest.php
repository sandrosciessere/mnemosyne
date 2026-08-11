<?php

namespace Tests\Feature\Ingestion;

use App\Enums\AssetIngestionStatus;
use App\Enums\IngestionRunStatus;
use App\Exceptions\Library\StorageException;
use App\Jobs\RunIngestionStageJob;
use App\Models\BookAccessGrant;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use App\Models\User;
use App\Services\Ingestion\IngestionOrchestrator;
use App\Services\Library\LibraryStorage;
use App\Services\Library\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IngestionSafetyRegressionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_retry_after_exceeds_the_ingestion_job_timeout(): void
    {
        // A stage job runs up to 600s (RunIngestionStageJob::$timeout and the
        // supervisor-ingestion timeout). retry_after MUST exceed it or Redis
        // redelivers a still-running stage, producing phantom failures.
        $jobTimeout = (new RunIngestionStageJob(1, 'hash'))->timeout;
        $this->assertGreaterThan(
            $jobTimeout,
            (int) config('queue.connections.redis.retry_after'),
            'redis retry_after must exceed the ingestion job timeout',
        );
        $this->assertGreaterThan($jobTimeout, (int) env('REDIS_QUEUE_RETRY_AFTER', 660) ?: 660);
    }

    public function test_promotion_refuses_bytes_that_do_not_match_the_content_address(): void
    {
        Storage::fake('data');
        $storage = app(LibraryStorage::class);

        $incoming = 'library/incoming/x/source.epub';
        Storage::disk('data')->put($incoming, 'the real bytes');
        $realSha = hash('sha256', 'the real bytes');
        $wrongSha = hash('sha256', 'different bytes');

        // Wrong content address → refuse, and never write a poisoned original.
        try {
            $storage->promoteToOriginal($incoming, $wrongSha);
            $this->fail('expected a hash-mismatch StorageException');
        } catch (StorageException $exception) {
            $this->assertSame('PROMOTION_HASH_MISMATCH', $exception->errorCode);
        }
        $this->assertFalse(Storage::disk('data')->exists(BookAsset::originalStoragePath($wrongSha)));

        // Correct content address → promoted, and the stored bytes hash to it.
        $dest = $storage->promoteToOriginal($incoming, $realSha);
        $this->assertTrue(Storage::disk('data')->exists($dest));
        $this->assertSame($realSha, hash('sha256', Storage::disk('data')->get($dest)));
    }

    public function test_cancelling_a_needs_review_run_returns_the_asset_to_pending(): void
    {
        $asset = BookAsset::factory()->create(['ingestion_status' => AssetIngestionStatus::NeedsReview]);
        $submission = BookSubmission::factory()->approved()->create(['book_asset_id' => $asset->id]);
        $run = IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'book_asset_id' => $asset->id,
            'status' => IngestionRunStatus::NeedsReview,
            'current_stage' => 'validate',
        ]);

        app(IngestionOrchestrator::class)->requestCancel($run->refresh());

        $this->assertSame(IngestionRunStatus::Cancelled, $run->refresh()->status);
        // The asset must not be stranded in needs_review with no owning run.
        $this->assertSame(AssetIngestionStatus::Pending, $asset->refresh()->ingestion_status);
    }

    public function test_submission_cancel_works_while_its_run_is_paused(): void
    {
        $submission = BookSubmission::factory()->approved()->create();
        $run = IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => IngestionRunStatus::Paused,
            'current_stage' => 'parse',
        ]);

        app(SubmissionService::class)->cancel($submission->refresh());

        $this->assertSame(IngestionRunStatus::Cancelled, $run->refresh()->status);
    }

    public function test_ungranted_user_cannot_download_another_users_book(): void
    {
        Storage::fake('data');
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $sha = str_repeat('a', 64);
        $path = BookAsset::originalStoragePath($sha);
        Storage::disk('data')->put($path, 'epub');
        $asset = BookAsset::factory()->create([
            'sha256' => $sha,
            'storage_path' => $path,
            'ingestion_status' => AssetIngestionStatus::ReadyForEnrichment,
        ]);
        (new BookAccessGrant)->forceFill([
            'user_id' => $owner->id, 'book_asset_id' => $asset->id, 'source' => 'submission',
        ])->save();

        // Grantee can download.
        $this->actingAs($owner)->get('/library/books/'.$asset->public_id.'/download')->assertOk();
        // A stranger without a grant is denied — not served the file.
        $this->actingAs($stranger)->get('/library/books/'.$asset->public_id.'/download')->assertForbidden();
    }
}
