<?php

namespace Tests\Feature\Ingestion;

use App\Enums\IngestionRunStatus;
use App\Models\BookSubmission;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithFakeWorker;
use Tests\TestCase;

class PauseResumeTest extends TestCase
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

    private function approvedSubmission(): BookSubmission
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/library/submissions', [
            'epub' => UploadedFile::fake()->createWithContent(uniqid().'.epub', 'PK-'.uniqid()),
        ]);
        $submission = BookSubmission::query()->latest('id')->first();
        $this->actingAs($this->admin)->post('/admin/submissions/'.$submission->public_id.'/approve');

        return $submission->refresh();
    }

    public function test_pause_queued_run_prevents_execution_and_resume_completes(): void
    {
        $this->fakeHappyWorker();
        $submission = $this->approvedSubmission();
        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Queued, $run->status);

        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/pause')
            ->assertRedirect();
        $this->assertSame(IngestionRunStatus::Paused, $run->refresh()->status);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.paused']);

        // The already-queued hash job wakes up, sees the pause, does nothing.
        $this->drainQueue();
        $this->assertSame(IngestionRunStatus::Paused, $run->refresh()->status);
        $this->assertSame(0, $run->attempts()->count());

        // Resume: re-dispatched from the checkpoint and completes.
        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/resume')
            ->assertRedirect();
        $this->drainQueue();

        $run->refresh();
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.resumed']);
        // Every stage executed exactly once despite the pause round-trip.
        $this->assertSame(
            ['hash', 'validate', 'parse', 'normalize', 'structure'],
            $run->attempts->pluck('stage.value')->all(),
        );
    }

    public function test_pause_mid_pipeline_stops_at_stage_boundary_and_resumes_from_checkpoint(): void
    {
        $this->fakeHappyWorker();
        $submission = $this->approvedSubmission();
        $run = $submission->latestRun;

        // Execute ONLY the hash stage; validate is now queued.
        $this->workOneJob();
        $run->refresh();
        $this->assertSame(IngestionRunStatus::Running, $run->status);
        $this->assertSame('validate', $run->current_stage->value);
        $this->assertSame(1, $this->pendingJobs());

        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/pause')
            ->assertRedirect();

        // The queued validate job is consumed without executing anything.
        $this->drainQueue();
        $run->refresh();
        $this->assertSame(IngestionRunStatus::Paused, $run->status);
        $this->assertSame(['hash'], $run->attempts->pluck('stage.value')->all());

        // Resume: continues at validate (hash NOT repeated) and finishes.
        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/resume')
            ->assertRedirect();
        $this->drainQueue();

        $run->refresh();
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->attempts()->where('stage', 'hash')->count());
        $this->assertSame(1, $run->attempts()->where('stage', 'validate')->count());
    }

    public function test_global_pause_blocks_new_dispatch_and_survives_as_persisted_state(): void
    {
        $this->fakeHappyWorker();

        $this->actingAs($this->admin)->post('/admin/processing/pause')->assertRedirect();
        $this->assertTrue(SystemSetting::ingestionPaused());
        $this->assertDatabaseHas('ingestion_events', ['type' => 'ingestion.paused_globally']);
        // Persisted in the database — it survives an application restart.
        $this->assertDatabaseHas('system_settings', ['key' => 'ingestion_paused']);

        // Approval while paused: run created and parked, no job dispatched.
        $submission = $this->approvedSubmission();
        $run = $submission->latestRun;
        $this->assertSame(IngestionRunStatus::Queued, $run->status);
        $this->assertSame(0, $this->pendingJobs());

        // Global resume re-dispatches queued runs and processing completes.
        $this->actingAs($this->admin)->post('/admin/processing/resume')->assertRedirect();
        $this->assertFalse(SystemSetting::ingestionPaused());
        $this->assertGreaterThan(0, $this->pendingJobs());
        $this->drainQueue();
        $this->assertSame(IngestionRunStatus::Succeeded, $run->refresh()->status);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'ingestion.resumed_globally']);
    }

    public function test_global_pause_mid_pipeline_parks_at_boundary(): void
    {
        $this->fakeHappyWorker();
        $submission = $this->approvedSubmission();
        $run = $submission->latestRun;

        // Hash executes; then the global pause lands before validate runs.
        $this->workOneJob();
        $this->actingAs($this->admin)->post('/admin/processing/pause')->assertRedirect();

        // The queued validate job parks the run durably back in `queued`.
        $this->drainQueue();
        $run->refresh();
        $this->assertSame(IngestionRunStatus::Queued, $run->status);
        $this->assertSame(['hash'], $run->attempts->pluck('stage.value')->all());

        $this->actingAs($this->admin)->post('/admin/processing/resume')->assertRedirect();
        $this->drainQueue();
        $this->assertSame(IngestionRunStatus::Succeeded, $run->refresh()->status);
    }

    public function test_paused_run_can_be_cancelled_and_priority_changed_but_not_retried(): void
    {
        $this->fakeHappyWorker();

        $first = $this->approvedSubmission();
        $run = $first->latestRun;
        $this->actingAs($this->admin)->post('/admin/processing/runs/'.$run->public_id.'/pause');

        // Priority change on a paused run is allowed (still active).
        $this->actingAs($this->admin)
            ->patch('/admin/processing/runs/'.$run->public_id.'/priority', ['priority' => 'high'])
            ->assertRedirect();
        $this->assertSame('high', $run->refresh()->priority->value);

        // Retry is for failed/needs_review only.
        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/retry')
            ->assertSessionHas('error');

        // Cancellation finalizes immediately (no job is in flight).
        $this->actingAs($this->admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/cancel')
            ->assertRedirect();
        $this->assertSame(IngestionRunStatus::Cancelled, $run->refresh()->status);
    }

    public function test_pause_endpoints_are_admin_only(): void
    {
        $user = User::factory()->create();
        $submission = $this->approvedSubmission();
        $run = $submission->latestRun;

        $this->actingAs($user)->post('/admin/processing/pause')->assertForbidden();
        $this->actingAs($user)
            ->post('/admin/processing/runs/'.$run->public_id.'/pause')
            ->assertForbidden();
    }
}
