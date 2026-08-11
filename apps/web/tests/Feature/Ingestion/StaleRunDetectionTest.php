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

    public function test_detect_stale_never_requeues_a_queued_backlog_run(): void
    {
        // A long queue wait is legitimate at scale and must NOT be treated
        // as stale — requeuing on age was the double-dispatch source.
        $waiting = IngestionRun::factory()->create([
            'status' => IngestionRunStatus::Queued,
            'heartbeat_at' => now()->subHours(6),
        ]);

        $this->artisan('mnemosyne:ingestion:detect-stale')->assertSuccessful();

        $this->assertSame(IngestionRunStatus::Queued, $waiting->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_paused_run_is_never_marked_stale(): void
    {
        $paused = IngestionRun::factory()->create([
            'status' => IngestionRunStatus::Paused,
            'current_stage' => 'parse',
            'heartbeat_at' => now()->subHours(6),
        ]);

        $this->artisan('mnemosyne:ingestion:detect-stale')->assertSuccessful();

        $this->assertSame(IngestionRunStatus::Paused, $paused->refresh()->status);
    }

    public function test_requeue_lost_command_explicitly_redispatches_old_queued_runs(): void
    {
        $lost = IngestionRun::factory()->create([
            'status' => IngestionRunStatus::Queued,
            'current_stage' => null,
            'heartbeat_at' => now()->subHours(2),
        ]);
        $recent = IngestionRun::factory()->create([
            'status' => IngestionRunStatus::Queued,
            'heartbeat_at' => now()->subMinutes(5),
        ]);

        $this->artisan('mnemosyne:ingestion:requeue-lost', ['--min-age-minutes' => 60])->assertSuccessful();

        Queue::assertPushed(RunIngestionStageJob::class, fn ($job) => $job->runId === $lost->id);
        Queue::assertNotPushed(RunIngestionStageJob::class, fn ($job) => $job->runId === $recent->id);
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
