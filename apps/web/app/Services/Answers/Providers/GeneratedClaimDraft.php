<?php

namespace App\Services\Answers\Providers;

/**
 * One generator-proposed claim, already schema-validated: claim keys
 * are CL1..CLn, evidence keys are verified members of the current
 * EvidencePacket, the suggested label is advisory only.
 */
class GeneratedClaimDraft
{
    /** @param list<string> $evidenceKeys */
    public function __construct(
        public readonly string $claimKey,
        public readonly string $text,
        public readonly string $suggestedLabel,
        public readonly array $evidenceKeys,
    ) {}
}
