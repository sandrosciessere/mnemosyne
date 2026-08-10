<?php

namespace App\Enums;

enum IngestionRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Paused = 'paused';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case Succeeded = 'succeeded';
    case Cancelled = 'cancelled';
    // Terminal: an admin intentionally marked the book unsupported.
    case Skipped = 'skipped';

    /** Statuses for which the run still owns the asset's pipeline. */
    public static function activeCases(): array
    {
        return [self::Queued, self::Running, self::Paused, self::NeedsReview];
    }

    public function isActive(): bool
    {
        return in_array($this, self::activeCases(), true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }
}
