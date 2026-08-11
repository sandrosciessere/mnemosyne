<?php

namespace App\Services\Ingestion;

use App\Enums\AssetIngestionStatus;
use App\Enums\IngestionEventType;
use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Models\BookAccessGrant;
use App\Models\BookAsset;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Models\User;
use App\Services\Library\LibraryStorage;
use Illuminate\Support\Facades\DB;

/**
 * The only place that moves an IngestionRun between statuses. Every
 * transition is transactional and appends a timeline event, so the answer
 * to "what happened to this EPUB?" is always in the database.
 */
class RunStateMachine
{
    public function __construct(private readonly LibraryStorage $storage) {}

    public function markRunning(IngestionRun $run, IngestionStage $stage): void
    {
        DB::transaction(function () use ($run, $stage) {
            $run->forceFill([
                'status' => IngestionRunStatus::Running,
                'current_stage' => $stage,
                'started_at' => $run->started_at ?? now(),
                'heartbeat_at' => now(),
            ])->save();

            if ($run->wasChanged('status')) {
                IngestionEvent::record(IngestionEventType::RunStarted, run: $run);
            }

            $run->asset?->forceFill(['ingestion_status' => AssetIngestionStatus::Processing])->save();
        });
    }

    public function markNeedsReview(IngestionRun $run, IngestionStage $stage, array $issues): void
    {
        DB::transaction(function () use ($run, $stage, $issues) {
            $run->forceFill([
                'status' => IngestionRunStatus::NeedsReview,
                'current_stage' => $stage,
                'review_issues' => $issues,
                'heartbeat_at' => now(),
            ])->save();

            IngestionEvent::record(IngestionEventType::RunNeedsReview, run: $run, payload: [
                'stage' => $stage->value,
                'issues' => array_map(fn ($issue) => [
                    'code' => $issue['code'],
                    'overrideable' => (bool) ($issue['overrideable'] ?? false),
                ], $issues),
            ]);

            $run->asset?->forceFill(['ingestion_status' => AssetIngestionStatus::NeedsReview])->save();
        });
    }

    public function markFailed(IngestionRun $run, string $errorCode, string $errorMessage): void
    {
        DB::transaction(function () use ($run, $errorCode, $errorMessage) {
            $run->forceFill([
                'status' => IngestionRunStatus::Failed,
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'last_error_code' => $errorCode,
                'last_error_message' => mb_substr($errorMessage, 0, 1000),
            ])->save();

            IngestionEvent::record(IngestionEventType::RunFailed, run: $run, payload: [
                'stage' => $run->current_stage?->value,
                'error_code' => $errorCode,
            ]);

            $run->asset?->forceFill(['ingestion_status' => AssetIngestionStatus::Failed])->save();
        });

        if ($run->asset !== null) {
            $this->finalizeWaitingDuplicates($run->asset);
        }
    }

    public function markCancelled(IngestionRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run->forceFill([
                'status' => IngestionRunStatus::Cancelled,
                'finished_at' => now(),
                'heartbeat_at' => now(),
            ])->save();

            IngestionEvent::record(IngestionEventType::RunCancelled, run: $run, payload: [
                'stage' => $run->current_stage?->value,
            ]);

            // An asset abandoned mid-pipeline goes back to pending: a new
            // run (e.g. another submission of the same file) can pick it up.
            // This covers both an in-flight asset (processing) and one left
            // parked in needs_review by the run being cancelled — otherwise
            // the asset would be stranded in needs_review with no owning run.
            $asset = $run->asset;
            if ($asset !== null && in_array($asset->ingestion_status, [
                AssetIngestionStatus::Processing,
                AssetIngestionStatus::NeedsReview,
            ], true)) {
                $asset->forceFill(['ingestion_status' => AssetIngestionStatus::Pending])->save();
            }
        });

        // Cancelling a PRODUCING run must not strand the exact-duplicate runs
        // parked on its asset: cancelled is terminal but non-mirrorable (the
        // asset earned no outcome), so unlike markFailed/markSucceeded/
        // markUnsupported we cannot just mirror it. Instead promote one waiter
        // to continue the now-orphaned asset; the rest stay parked and are
        // resolved when that new producer finalizes. No waiter is left queued
        // on a terminal producer.
        if ($run->asset !== null) {
            $this->adoptWaitingDuplicatesAfterProducerGone($run->asset);
        }
    }

    /**
     * A producing run was cancelled, returning its asset to `pending` with no
     * active producer. Promote the oldest still-active exact-duplicate waiter
     * to the new producer (adopt the pending asset, resume from its durable
     * checkpoint — Hash already succeeded on a waiter, so it continues at
     * validate); the remaining waiters keep waiting on the same asset and are
     * resolved by `finalizeWaitingDuplicates` when that producer finalizes.
     *
     * The claim is a guarded atomic update so exactly one waiter can adopt,
     * with the one-active-run-per-asset partial unique index as the backstop.
     * A promoted waiter that itself carries a cancel request is honored at its
     * next stage boundary, which re-enters markCancelled and promotes the
     * next waiter — so the chain always terminates with every waiter resolved.
     */
    private function adoptWaitingDuplicatesAfterProducerGone(BookAsset $asset): void
    {
        $activeStatuses = array_map(fn ($case) => $case->value, IngestionRunStatus::activeCases());

        // If something is already actively producing the asset (e.g. a fresh
        // submission adopted it in the meantime), the normal waiting/finalize
        // flow will resolve the waiters — nothing to do here.
        $hasActiveProducer = $asset->runs()
            ->whereNotNull('book_asset_id')
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($hasActiveProducer) {
            return;
        }

        $waiter = IngestionRun::query()
            ->where('waiting_on_asset_id', $asset->id)
            ->whereIn('status', $activeStatuses)
            ->orderBy('id')
            ->first();

        if ($waiter === null) {
            return; // No parked waiters — nothing to promote.
        }

        $promoted = DB::transaction(function () use ($waiter, $asset, $activeStatuses) {
            // Atomic claim: only if this run is still an active waiter on the
            // asset. Zero rows ⇒ another path already moved it; converge.
            $affected = IngestionRun::query()
                ->whereKey($waiter->id)
                ->where('waiting_on_asset_id', $asset->id)
                ->whereIn('status', $activeStatuses)
                ->update([
                    'book_asset_id' => $asset->id,
                    'waiting_on_asset_id' => null,
                    'status' => IngestionRunStatus::Queued->value,
                    'heartbeat_at' => now(),
                ]);

            if ($affected === 0) {
                return null;
            }

            $asset->forceFill(['ingestion_status' => AssetIngestionStatus::Processing])->save();

            $fresh = $waiter->fresh();

            IngestionEvent::record(IngestionEventType::RunQueued, run: $fresh, payload: [
                'reason' => 'adopted_after_producer_cancelled',
                'asset' => $asset->public_id,
            ]);

            return $fresh;
        });

        if ($promoted === null) {
            return;
        }

        // Resume from the durable checkpoint, outside the transaction. Under
        // global pause this is a no-op and resumeGlobally() re-dispatches it.
        $orchestrator = app(IngestionOrchestrator::class);
        $orchestrator->dispatchStage($promoted, $orchestrator->nextDispatchStage($promoted));
    }

    /**
     * Terminal success: the asset is structurally understood and becomes
     * READY_FOR_ENRICHMENT (not "ready" — semantic enrichment is a future
     * milestone). A run that accumulated recoverable warnings lands in
     * READY_FOR_ENRICHMENT_WITH_WARNINGS so it is visibly distinct from a
     * clean book. Grants the submitter access and cleans the incoming area.
     */
    public function markSucceeded(IngestionRun $run): void
    {
        DB::transaction(function () use ($run) {
            // Only real content warnings count toward ready-with-warnings;
            // transient worker-retry notices are operational noise and must
            // not flip a clean book (same predicate RunPresentation renders).
            $warningCount = $run->events()->contentWarnings()->count();

            $run->forceFill([
                'status' => IngestionRunStatus::Succeeded,
                'progress' => 100,
                'finished_at' => now(),
                'heartbeat_at' => now(),
            ])->save();

            $asset = $run->asset;
            if ($asset !== null) {
                $hasWarnings = $warningCount > 0
                    || $asset->validation_status === 'passed_with_warnings'
                    || ($run->overridden_issues ?? []) !== [];

                $asset->forceFill([
                    'ingestion_status' => $hasWarnings
                        ? AssetIngestionStatus::ReadyForEnrichmentWithWarnings
                        : AssetIngestionStatus::ReadyForEnrichment,
                    'pipeline_version' => $run->pipeline_version,
                    'structure_summary' => array_merge($asset->structure_summary ?? [], [
                        'warnings_count' => $warningCount,
                    ]),
                ])->save();
            }

            $this->grantSubmitterAccess($run);

            IngestionEvent::record(IngestionEventType::RunSucceeded, run: $run, payload: [
                'pipeline_version' => $run->pipeline_version,
            ]);
        });

        // Filesystem cleanup happens outside the transaction: losing a
        // temp file cleanup on crash is harmless; losing the state is not.
        $this->cleanupIncomingFor($run);

        // Duplicate submissions that parked waiting on this asset can now be
        // finalized against its terminal outcome.
        if ($run->asset !== null) {
            $this->finalizeWaitingDuplicates($run->asset);
        }
    }

    /**
     * Park an exact-duplicate run that arrived while another run still owns
     * the asset's pipeline. It waits (queued) without owning the asset until
     * the owning run reaches a terminal state, then mirrors that outcome.
     */
    public function parkWaitingDuplicate(IngestionRun $run): void
    {
        // Atomic, guarded transition (never a stale in-memory model write).
        // Only a run that is STILL an in-flight waiter — running/queued and
        // still pointing at the asset it parked on — may be moved to the
        // parked `queued` state. If the producing run finalized this waiter
        // in the race window (mirrored it to a terminal state and cleared
        // waiting_on_asset_id), this conditional update affects zero rows and
        // we leave that terminal outcome intact instead of resurrecting it.
        $affected = IngestionRun::query()
            ->whereKey($run->id)
            ->whereNotNull('waiting_on_asset_id')
            ->whereIn('status', [
                IngestionRunStatus::Queued->value,
                IngestionRunStatus::Running->value,
            ])
            ->update([
                'status' => IngestionRunStatus::Queued->value,
                'heartbeat_at' => now(),
            ]);

        if ($affected === 0) {
            return; // Already finalized by the producer — do not resurrect.
        }

        $run->refresh();

        IngestionEvent::record(IngestionEventType::RunQueued, run: $run, payload: [
            'reason' => 'waiting_on_existing_asset',
            'waiting_on_asset_id' => $run->waitingOnAsset?->public_id,
        ]);
    }

    /**
     * Finalize a duplicate run by mirroring the terminal state of the asset
     * it points at — the file was never reprocessed, so the outcome must
     * match the original: ready → succeeded (+ grant), unsupported →
     * skipped, failed → failed. Never claims a success the original did not
     * earn, and never reverses an admin unsupported decision.
     */
    public function mirrorDuplicateOutcome(IngestionRun $run): void
    {
        $asset = $run->asset;

        if ($asset === null) {
            // Nothing to mirror against — treat as a benign failed run.
            $this->markFailed($run, 'DUPLICATE_NO_ASSET', 'Duplicate run had no asset to mirror.');

            return;
        }

        $mirroredFailure = false;

        DB::transaction(function () use ($run, $asset, &$mirroredFailure) {
            $run->forceFill(['waiting_on_asset_id' => null])->save();

            if ($asset->ingestion_status->isReadyForEnrichment()) {
                $run->forceFill([
                    'status' => IngestionRunStatus::Succeeded,
                    'progress' => 100,
                    'finished_at' => now(),
                    'heartbeat_at' => now(),
                ])->save();

                $this->grantSubmitterAccess($run);

                IngestionEvent::record(IngestionEventType::RunSucceeded, run: $run, payload: [
                    'duplicate_of' => $asset->public_id,
                    'mirrored_status' => $asset->ingestion_status->value,
                ]);

                return;
            }

            if ($asset->ingestion_status === AssetIngestionStatus::Unsupported) {
                $run->forceFill([
                    'status' => IngestionRunStatus::Skipped,
                    'finished_at' => now(),
                    'heartbeat_at' => now(),
                ])->save();

                IngestionEvent::record(IngestionEventType::RunMarkedUnsupported, run: $run, payload: [
                    'duplicate_of' => $asset->public_id,
                    'reason' => 'mirrors an asset an administrator marked unsupported',
                ]);

                return;
            }

            // Failed (or any other non-ready terminal): mirror the failure.
            $mirroredFailure = true;
            $run->forceFill([
                'status' => IngestionRunStatus::Failed,
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'last_error_code' => 'DUPLICATE_OF_FAILED_ASSET',
                'last_error_message' => 'Exact duplicate of an asset whose ingestion failed.',
            ])->save();

            IngestionEvent::record(IngestionEventType::RunFailed, run: $run, payload: [
                'duplicate_of' => $asset->public_id,
                'error_code' => 'DUPLICATE_OF_FAILED_ASSET',
            ]);
        });

        // A mirrored FAILURE keeps its incoming copy so an admin retry can
        // reprocess the content (consistent with markFailed) — the checkpoint
        // retry resumes at the next stage on the shared asset. Ready and
        // unsupported are never reprocessed, so their copy is reclaimed.
        if (! $mirroredFailure) {
            $this->cleanupIncomingFor($run);
        }
    }

    /**
     * When an owning run reaches a terminal state, resolve every duplicate
     * run that parked waiting on its asset.
     */
    private function finalizeWaitingDuplicates(BookAsset $asset): void
    {
        $waiting = IngestionRun::query()
            ->where('waiting_on_asset_id', $asset->id)
            ->whereIn('status', array_map(fn ($case) => $case->value, IngestionRunStatus::activeCases()))
            ->get();

        foreach ($waiting as $run) {
            // Adopt the asset for provenance/grants, then mirror. The owning
            // run is already terminal, so linking a now-terminal duplicate
            // cannot violate the one-active-run-per-asset index.
            $run->forceFill(['book_asset_id' => $asset->id])->save();
            $run->setRelation('asset', $asset);
            $this->mirrorDuplicateOutcome($run);
        }
    }

    private function cleanupIncomingFor(IngestionRun $run): void
    {
        $submission = $run->submission;
        if ($submission?->incoming_path !== null) {
            $this->storage->cleanupIncoming($submission);
            $submission->forceFill(['incoming_path' => null])->save();
        }
    }

    /**
     * Also used when a duplicate short-circuits the pipeline: the
     * submitter of a duplicate gets access to the existing asset.
     */
    public function grantSubmitterAccess(IngestionRun $run): void
    {
        $submission = $run->submission;
        $asset = $run->asset;

        if ($submission?->user_id === null || $asset === null) {
            return;
        }

        $this->grantSubmitterAccessTo($submission->user_id, $asset->id);
    }

    public function grantSubmitterAccessTo(int $userId, int $assetId): void
    {
        $exists = BookAccessGrant::query()
            ->where('user_id', $userId)
            ->where('book_asset_id', $assetId)
            ->exists();

        if (! $exists) {
            (new BookAccessGrant)->forceFill([
                'user_id' => $userId,
                'book_asset_id' => $assetId,
                'source' => 'submission',
            ])->save();
        }
    }

    public function heartbeat(IngestionRun $run): void
    {
        $run->forceFill(['heartbeat_at' => now()])->save();
    }

    /** Cooperative per-run pause: durable, honored at stage boundaries. */
    public function markPaused(IngestionRun $run, ?User $actor = null): void
    {
        DB::transaction(function () use ($run, $actor) {
            $run->forceFill([
                'status' => IngestionRunStatus::Paused,
                'heartbeat_at' => now(),
            ])->save();

            IngestionEvent::record(IngestionEventType::RunPaused, run: $run, actor: $actor, payload: [
                'stage' => $run->current_stage?->value,
            ]);
        });
    }

    /**
     * Admin decision: this book is unsupported for now. Terminal for the
     * run (skipped) and for the asset (unsupported). A corrected file can
     * arrive later as a NEW submission — the immutable asset is never
     * overwritten.
     */
    public function markUnsupported(IngestionRun $run, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($run, $actor, $reason) {
            $run->forceFill([
                'status' => IngestionRunStatus::Skipped,
                'finished_at' => now(),
                'heartbeat_at' => now(),
            ])->save();

            IngestionEvent::record(IngestionEventType::RunMarkedUnsupported, run: $run, actor: $actor, payload: [
                'stage' => $run->current_stage?->value,
                'reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
            ]);

            $run->asset?->forceFill(['ingestion_status' => AssetIngestionStatus::Unsupported])->save();
        });

        if ($run->asset !== null) {
            $this->finalizeWaitingDuplicates($run->asset);
        }
    }
}
