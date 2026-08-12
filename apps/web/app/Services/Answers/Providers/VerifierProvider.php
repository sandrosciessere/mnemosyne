<?php

namespace App\Services\Answers\Providers;

use App\Services\Answers\EvidencePacket;

/**
 * Independent claim verification. Deliberately a separate capability
 * from GenerationProvider (independently configurable) even while both
 * resolve to the same local model today.
 */
interface VerifierProvider
{
    public function verify(string $question, EvidencePacket $packet, GeneratedClaimDraft $claim): VerificationResult;

    public function identity(): ProviderIdentity;
}
