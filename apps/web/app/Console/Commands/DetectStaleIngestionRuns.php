<?php

namespace App\Console\Commands;

use App\Enums\IngestionEventType;
use App\Enums\IngestionRunStatus;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Services\Ingestion\RunStateMachine;
use Illuminate\Console\Command;

/**
 * Stale detection distinguishes "a worker took ownership of a stage and
 * then went silent" from "a run is simply waiting its turn in the queue".
 *
 * Only the former is a failure. A run in `running` whose heartbeat stopped
 * for longer than the threshold had its worker/job die mid-stage — it is
 * marked failed (retryable) so an admin can retry from the durable
 * checkpoint. A `queued` run is NEVER treated as stale on the basis of how
 * long it has waited: at 100k-book scale with low concurrency a legitimate
 * queue wait can last hours or days, and requeuing on age caused duplicate
 * dispatch. Recovery of a genuinely lost queued job (e.g. Redis flushed) is
 * a deliberate, checkpoint-guarded maintenance action
 * (`mnemosyne:ingestion:requeue-lost`), not an inference from queue latency.
 */
class DetectStaleIngestionRuns extends Command
{
    protected $signature = 'mnemosyne:ingestion:detect-stale
        {--dry-run : Report stale runs without changing anything}';

    protected $description = 'Mark ingestion runs whose worker went silent mid-stage as failed (retryable). Queue waits are never treated as stale.';

    public function handle(RunStateMachine $stateMachine): int
    {
        $threshold = now()->subMinutes((int) config('mnemosyne.ingestion.stale_after_minutes'));
        $dryRun = (bool) $this->option('dry-run');

        // Running runs with a silent heartbeat: the worker/job died mid-
        // stage. A running stage is always shorter than the worker timeout
        // (< threshold), so a stale heartbeat here genuinely means death,
        // not slow progress. Mark failed so an admin can retry — never an
        // automatic loop. Paused/queued runs are intentionally excluded.
        $staleRunning = IngestionRun::query()
            ->where('status', IngestionRunStatus::Running)
            ->where('heartbeat_at', '<', $threshold)
            ->get();

        foreach ($staleRunning as $run) {
            $this->warn("stale running run {$run->public_id} (stage {$run->current_stage?->value})");

            if ($dryRun) {
                continue;
            }

            IngestionEvent::record(IngestionEventType::RunMarkedStale, run: $run, payload: [
                'stage' => $run->current_stage?->value,
                'heartbeat_at' => $run->heartbeat_at?->toIso8601String(),
            ]);
            $stateMachine->markFailed($run, 'STALE_RUN', 'No heartbeat for too long; the stage job likely died. Retry from the admin panel.');
        }

        $this->info(sprintf('stale check complete: %d stale running run(s) marked failed', $staleRunning->count()));

        return self::SUCCESS;
    }
}
