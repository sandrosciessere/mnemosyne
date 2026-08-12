<?php

namespace App\Services\Answers\Providers;

/**
 * Normalized independent verifier judgment for one claim.
 * supportedEvidenceKeys ⊆ current packet keys (validated); the verifier
 * may select better units than the generator proposed but can never
 * invent keys.
 */
class VerificationResult
{
    /** @param list<string> $supportedEvidenceKeys */
    public function __construct(
        public readonly string $claimKey,
        public readonly string $supportLevel,
        public readonly array $supportedEvidenceKeys,
        public readonly ?string $reasonCode,
    ) {}
}
