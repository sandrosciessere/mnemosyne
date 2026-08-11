<?php

namespace App\Jobs;

use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Models\IngestionRun;
use App\Services\Ingestion\RunStateMachine;
use App\Services\Ingestion\StageExecutor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes exactly one pipeline stage for one run, then (on success)
 * dispatches the next stage as a fresh job. One stage = one job keeps
 * every checkpoint durable and every queue wait preemptible by priority.
 *
 * Duplicate delivery is made harmless on two levels:
 *  - queue level: ShouldBeUniqueUntilProcessing keyed on the run means a
 *    second job for a run cannot be enqueued while one is still pending
 *    (the lock releases the moment processing starts, so the next stage /
 *    a backoff retry can still be dispatched from within the running job);
 *  - execution level (authoritative): StageExecutor drops any job whose
 *    stage is not the run's current durable checkpoint. Database state is
 *    the source of truth; queue uniqueness is only a first line of defence.
 */
class RunIngestionStageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Transport-level tries. Domain-level retries (attempts, backoff,
     * retryable classification) are managed by StageExecutor.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $runId,
        public readonly string $stage,
    ) {}

    /**
     * One in-flight job per run. Keyed on the run (not run+stage) so a
     * stale-scanner requeue or a global-resume redispatch cannot stack a
     * second job on a run that already has one pending.
     */
    public function uniqueId(): string
    {
        return 'ingestion-run:'.$this->runId;
    }

    /**
     * Bound the lock so a job lost before it ever starts processing cannot
     * wedge a run forever; kept comfortably above the stage job timeout.
     */
    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(StageExecutor $executor): void
    {
        $run = IngestionRun::query()->find($this->runId);

        if ($run === null) {
            return; // Run deleted meanwhile: nothing to do.
        }

        $executor->execute($run, IngestionStage::from($this->stage));
    }

    /**
     * A genuine transport-level death (worker killed, timeout, unexpected
     * throwable that escaped the executor) must not leave the run wedged in
     * a non-terminal status with no job. Reconcile it to a retryable failed
     * state; stale-run detection remains the defence-in-depth backstop.
     */
    public function failed(?Throwable $exception): void
    {
        $run = IngestionRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        // Only reconcile a run still owned by this (dead) job. If it already
        // reached a terminal state or was parked (paused/queued under global
        // pause), leave it alone.
        if (! in_array($run->status, [IngestionRunStatus::Running], true)) {
            return;
        }

        app(RunStateMachine::class)->markFailed(
            $run,
            'STAGE_JOB_FAILED',
            'The stage job terminated unexpectedly ('.$this->stage.'). Retry from the admin panel.'
                .($exception !== null ? ' '.mb_substr($exception->getMessage(), 0, 300) : ''),
        );
    }
}
