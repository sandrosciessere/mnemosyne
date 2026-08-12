<?php

namespace App\Services\Answers\Providers;

use App\Enums\VerifierSupportLevel;
use App\Exceptions\Answers\ProviderInvalidOutputException;
use App\Services\Answers\EvidencePacket;

/**
 * Application-level validation of verifier output. The verifier must
 * point at exact SENTENCE ATOMS (E3.S2) that exist in the packet — it
 * may choose different/better atoms than the generator proposed but
 * can never invent identifiers, cite whole units vaguely, or support a
 * claim with zero atoms.
 */
class VerifierOutputValidator
{
    /** @throws ProviderInvalidOutputException with VERIFIER_INVALID_OUTPUT */
    public function validate(array $raw, EvidencePacket $packet, GeneratedClaimDraft $claim): VerificationResult
    {
        $level = $raw['support_level'] ?? null;

        if (! is_string($level) || VerifierSupportLevel::tryFrom($level) === null) {
            $this->reject('unknown support_level');
        }

        $claimKey = $raw['claim_key'] ?? null;

        if ($claimKey !== $claim->claimKey) {
            $this->reject('claim_key mismatch');
        }

        $atomKeys = $raw['supported_atom_keys'] ?? null;

        if (! is_array($atomKeys)) {
            $this->reject('supported_atom_keys must be an array');
        }

        $atomKeys = array_values(array_unique($atomKeys));
        $evidenceKeys = [];

        foreach ($atomKeys as $atomKey) {
            if (! is_string($atomKey) || ! $packet->hasAtom($atomKey)) {
                $this->reject('unknown support atom '.(is_string($atomKey) ? $atomKey : gettype($atomKey)));
            }

            $evidenceKeys[] = EvidencePacket::unitKeyOf($atomKey);
        }

        if ($atomKeys === [] && in_array($level, ['direct', 'strong', 'interpretive', 'conflict'], true)) {
            $this->reject('a supported/conflict verdict requires support atoms');
        }

        $reason = $raw['reason_code'] ?? null;
        $reason = is_string($reason) ? mb_substr(trim($reason), 0, 64) : null;

        return new VerificationResult(
            $claim->claimKey,
            $level,
            $atomKeys,
            array_values(array_unique($evidenceKeys)),
            $reason === '' ? null : $reason,
        );
    }

    private function reject(string $reason): never
    {
        throw new ProviderInvalidOutputException(
            'VERIFIER_INVALID_OUTPUT',
            'Verifier output rejected: '.$reason,
        );
    }
}
