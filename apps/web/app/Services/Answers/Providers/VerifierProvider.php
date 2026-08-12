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
    /**
     * `$feedback` carries a bounded application hint on retry (e.g.
     * "your selected atoms do not state the claim — pick the asserting
     * sentence or answer none"); null on the first call.
     */
    public function verify(string $question, EvidencePacket $packet, GeneratedClaimDraft $claim, ?string $feedback = null): VerificationResult;

    public function identity(): ProviderIdentity;
}
