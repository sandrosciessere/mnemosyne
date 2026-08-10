<?php

namespace App\Console\Commands;

use App\Enums\IngestionEventType;
use App\Enums\IngestionRunStatus;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Services\Ingestion\IngestionOrchestrator;
use Illuminate\Console\Command;

/**
 * Deliberate recovery of queued runs whose job was genuinely lost (e.g.
 * Redis was flushed between dispatch and pickup). This is NOT automatic:
 * queue latency alone never means a job is lost, so requeuing on age would
 * double-dispatch a legitimately-backlogged run.
 *
 * Redispatch is safe by construction even if a run is not actually lost:
 *  - RunIngestionStageJob is unique-until-processing, so a run that still
 *    has a pending job is a no-op here;
 *  - StageExecutor's checkpoint guard drops any duplicate that slips
 *    through without re-executing a stage.
 *
 * An operator runs this only after a known queue-loss incident, optionally
 * narrowing to runs idle beyond a given age.
 */
class RequeueLostIngestionRuns extends Command
{
    protected $signature = 'mnemosyne:ingestion:requeue-lost
        {--min-age-minutes=60 : Only requeue queued runs whose heartbeat is older than this}
        {--dry-run : Report what would be requeued without dispatching}';

    protected $description = 'Explicitly redispatch queued runs whose job was lost (safe: uniqueness + checkpoint guard absorb non-lost runs).';

    public function handle(IngestionOrchestrator $orchestrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minAge = max(0, (int) $this->option('min-age-minutes'));
        $threshold = now()->subMinutes($minAge);

        $candidates = IngestionRun::query()
            ->where('status', IngestionRunStatus::Queued)
            ->where('heartbeat_at', '<', $threshold)
            ->orderBy('id')
            ->get();

        foreach ($candidates as $run) {
            $this->warn("requeue-lost candidate {$run->public_id} (queued, idle since {$run->heartbeat_at?->toIso8601String()})");

            if ($dryRun) {
                continue;
            }

            IngestionEvent::record(IngestionEventType::RunMarkedStale, run: $run, payload: [
                'action' => 'requeue_lost_manual',
            ]);
            $orchestrator->dispatchStage($run, $orchestrator->nextDispatchStage($run));
        }

        $this->info(sprintf('requeue-lost complete: %d candidate(s)%s', $candidates->count(), $dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
