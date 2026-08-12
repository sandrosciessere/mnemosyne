<?php

namespace App\Services\Answers\Providers;

/**
 * Normalized structured generator output. `status` is an answer-domain
 * status (answered | partially_answered | insufficient_evidence);
 * provider/runtime failures are exceptions, never statuses.
 */
class GenerationResult
{
    /** @param list<GeneratedClaimDraft> $claims */
    public function __construct(
        public readonly string $status,
        public readonly array $claims,
    ) {}
}
