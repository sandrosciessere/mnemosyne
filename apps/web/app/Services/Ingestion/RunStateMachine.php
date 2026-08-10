<?php

namespace App\Services\Ingestion;

use App\Enums\AssetIngestionStatus;
use App\Enums\IngestionEventType;
use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Models\BookAccessGrant;
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
            $asset = $run->asset;
            if ($asset !== null && $asset->ingestion_status === AssetIngestionStatus::Processing) {
                $asset->forceFill(['ingestion_status' => AssetIngestionStatus::Pending])->save();
            }
        });
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
            $warningCount = $run->events()->where('type', 'stage.warning')->count();

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
    }
}
