<?php

namespace App\Enums;

/**
 * Persisted grounded-answer pipeline lifecycle. User-visible progress
 * derives from THIS durable state, never from client-side timers.
 */
enum AnswerRunStatus: string
{
    case Queued = 'queued';
    case Retrieving = 'retrieving';
    case ExpandingRetrieval = 'expanding_retrieval';
    case Generating = 'generating';
    case Verifying = 'verifying';
    case Ready = 'ready';
    case Insufficient = 'insufficient';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ready, self::Insufficient, self::Failed => true,
            default => false,
        };
    }

    /** @return list<self> */
    public static function activeCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => ! $case->isTerminal()));
    }
}
