<?php

namespace App\Enums;

/**
 * Event type identifiers for the append-only ingestion_events timeline.
 * A plain constants class (not a backed enum) so future milestones can
 * add types without schema or enum migrations.
 */
final class IngestionEventType
{
    public const SubmissionCreated = 'submission.created';

    public const SubmissionApproved = 'submission.approved';

    public const SubmissionAutoApproved = 'submission.auto_approved';

    public const SubmissionRejected = 'submission.rejected';

    public const SubmissionCancelled = 'submission.cancelled';

    public const RunQueued = 'run.queued';

    public const RunStarted = 'run.started';

    public const StageStarted = 'stage.started';

    public const StageCompleted = 'stage.completed';

    public const StageWarning = 'stage.warning';

    public const RunNeedsReview = 'run.needs_review';

    public const RunFailed = 'run.failed';

    public const RunSucceeded = 'run.succeeded';

    public const RunCancelled = 'run.cancelled';

    public const RunMarkedStale = 'run.marked_stale';

    public const RunPaused = 'run.paused';

    public const RunResumed = 'run.resumed';

    public const RunMarkedUnsupported = 'run.marked_unsupported';

    public const IngestionPausedGlobally = 'ingestion.paused_globally';

    public const IngestionResumedGlobally = 'ingestion.resumed_globally';

    public const RetryRequested = 'run.retry_requested';

    public const CancelRequested = 'run.cancel_requested';

    public const IssueOverridden = 'run.issue_overridden';

    public const PriorityChanged = 'run.priority_changed';

    public const DuplicateExactDetected = 'asset.duplicate_exact';

    public const DuplicateCandidateDetected = 'asset.duplicate_candidate';

    public const AssetPromoted = 'asset.promoted_to_original';

    public const AssetReconciled = 'asset.reconciled';

    public const SettingChanged = 'system.setting_changed';

    private function __construct() {}
}
