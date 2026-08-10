<?php

namespace Tests\Feature\Ingestion;

use App\Enums\IngestionRunStatus;
use App\Jobs\RunIngestionStageJob;
use App\Models\IngestionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaleRunDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['mnemosyne.ingestion.stale_after_minutes' => 30]);
    }

    public function test_stale_running_run_is_marked_failed_retryable(): void
    {
        $stale = IngestionRun::factory()->create([
            'status' => IngestionRunStatus::Running,
            'current_stage' => 'parse',
            'heartbeat_at' => now()->subHour(),
        ]);
        $fresh = IngestionRun::factory()->create([
            'status' => IngestionRunStatus::Running,
            'current_stage' => 'parse',
            'heartbeat_at' => now()->subMinutes(5),
        ]);

        $this->artisan('mnemosyne:ingestion:detect-stale')->assertSuccessful();

        $this->assertSame(IngestionRunStatus::Failed, $stale->refresh()->status);
        $this->assertSame('STALE_RUN', $stale->last_error_code);
        $this->assertSame(IngestionRunStatus::Running, $fresh->refresh()->status);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.marked_stale']);
    }

    public function test_lost_queued_run_is_requeued(): void
    {
        $lost = IngestionRun::factory()->create([
            'status' => IngestionRunStatus::Queued,
            'heartbeat_at' => now()->subHour(),
        ]);

        $this->artisan('mnemosyne:ingestion:detect-stale')->assertSuccessful();

        $this->assertSame(IngestionRunStatus::Queued, $lost->refresh()->status);
        // Heartbeat refreshed so the next pass will not requeue again
        // within the threshold window.
        $this->assertTrue($lost->heartbeat_at->greaterThan(now()->subMinutes(1)));
        Queue::assertPushed(RunIngestionStageJob::class, fn ($job) => $job->runId === $lost->id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $stale = IngestionRun::factory()->create([
            'status' => IngestionRunStatus::Running,
            'heartbeat_at' => now()->subHour(),
        ]);

        $this->artisan('mnemosyne:ingestion:detect-stale', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(IngestionRunStatus::Running, $stale->refresh()->status);
        Queue::assertNothingPushed();
    }
}
