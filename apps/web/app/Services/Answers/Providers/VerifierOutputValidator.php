<?php

namespace App\Services\Answers\Providers;

use App\Enums\VerifierSupportLevel;
use App\Exceptions\Answers\ProviderInvalidOutputException;
use App\Services\Answers\EvidencePacket;

/**
 * Application-level validation of verifier output. The verifier may
 * select any keys FROM THE PACKET (it can choose better evidence than
 * the generator proposed) but can never invent keys or support a claim
 * with zero evidence.
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

        $keys = $raw['supported_evidence_keys'] ?? null;

        if (! is_array($keys)) {
            $this->reject('supported_evidence_keys must be an array');
        }

        $keys = array_values(array_unique($keys));

        foreach ($keys as $key) {
            if (! is_string($key) || ! $packet->has($key)) {
                $this->reject('unknown evidence key '.(is_string($key) ? $key : gettype($key)));
            }
        }

        if ($keys === [] && in_array($level, ['direct', 'strong', 'interpretive', 'conflict'], true)) {
            $this->reject('a supported/conflict verdict requires evidence keys');
        }

        $reason = $raw['reason_code'] ?? null;
        $reason = is_string($reason) ? mb_substr(trim($reason), 0, 64) : null;

        return new VerificationResult($claim->claimKey, $level, $keys, $reason === '' ? null : $reason);
    }

    private function reject(string $reason): never
    {
        throw new ProviderInvalidOutputException(
            'VERIFIER_INVALID_OUTPUT',
            'Verifier output rejected: '.$reason,
        );
    }
}
