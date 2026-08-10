<?php

namespace App\Console\Commands;

use App\Enums\IngestionEventType;
use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Services\Ingestion\IngestionOrchestrator;
use App\Services\Ingestion\RunStateMachine;
use Illuminate\Console\Command;

class DetectStaleIngestionRuns extends Command
{
    protected $signature = 'mnemosyne:ingestion:detect-stale
        {--dry-run : Report stale runs without changing anything}';

    protected $description = 'Detect ingestion runs whose heartbeat stopped and mark them failed (retryable) or requeue lost queued runs';

    public function handle(RunStateMachine $stateMachine, IngestionOrchestrator $orchestrator): int
    {
        $threshold = now()->subMinutes((int) config('mnemosyne.ingestion.stale_after_minutes'));
        $dryRun = (bool) $this->option('dry-run');

        // Running runs with a silent heartbeat: the worker/job died mid-
        // stage. Mark failed so an admin can retry — never auto-loop.
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

        // Queued runs whose job never arrived (e.g. Redis flushed between
        // dispatch and pickup): requeue exactly once per detection pass.
        $lostQueued = IngestionRun::query()
            ->where('status', IngestionRunStatus::Queued)
            ->where('heartbeat_at', '<', $threshold)
            ->get();

        foreach ($lostQueued as $run) {
            $this->warn("requeueing lost queued run {$run->public_id}");

            if ($dryRun) {
                continue;
            }

            IngestionEvent::record(IngestionEventType::RunMarkedStale, run: $run, payload: [
                'action' => 'requeued',
            ]);
            $stateMachine->heartbeat($run);
            $orchestrator->dispatchStage($run, $run->current_stage ?? IngestionStage::first());
        }

        $this->info(sprintf(
            'stale check complete: %d stale running, %d requeued',
            $staleRunning->count(),
            $lostQueued->count(),
        ));

        return self::SUCCESS;
    }
}
