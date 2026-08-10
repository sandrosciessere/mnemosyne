<?php

namespace App\Enums;

/**
 * Approval lifecycle of a BookSubmission. Ingestion progress is tracked
 * separately on the linked IngestionRun — keep the two lifecycles apart.
 */
enum SubmissionStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Rejected || $this === self::Cancelled;
    }
}
