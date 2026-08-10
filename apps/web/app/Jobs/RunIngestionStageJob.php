<?php

namespace App\Jobs;

use App\Enums\IngestionStage;
use App\Models\IngestionRun;
use App\Services\Ingestion\StageExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Executes exactly one pipeline stage for one run, then (on success)
 * dispatches the next stage as a fresh job. One stage = one job keeps
 * every checkpoint durable and every queue wait preemptible by priority.
 */
class RunIngestionStageJob implements ShouldQueue
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

    public function handle(StageExecutor $executor): void
    {
        $run = IngestionRun::query()->find($this->runId);

        if ($run === null) {
            return; // Run deleted meanwhile: nothing to do.
        }

        $executor->execute($run, IngestionStage::from($this->stage));
    }
}
