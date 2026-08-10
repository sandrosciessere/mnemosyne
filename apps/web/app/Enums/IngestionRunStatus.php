<?php

namespace App\Enums;

enum IngestionRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case Succeeded = 'succeeded';
    case Cancelled = 'cancelled';

    /** Statuses for which the run still owns the asset's pipeline. */
    public static function activeCases(): array
    {
        return [self::Queued, self::Running, self::NeedsReview];
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
