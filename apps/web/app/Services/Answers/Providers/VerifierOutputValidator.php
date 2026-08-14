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
        $normalized = [];
        $evidenceKeys = [];

        foreach ($atomKeys as $atomKey) {
            if (! is_string($atomKey)) {
                $this->reject('unknown support atom '.gettype($atomKey));
            }

            // Unambiguous normalization: a bare unit key (E4) whose unit
            // has exactly ONE atom means E4.S1 — no vagueness possible.
            // Bare references to multi-atom units stay invalid: the
            // verifier must identify the exact asserting sentence.
            if (preg_match('/^E\d+$/', $atomKey) === 1
                && isset($packet->units[$atomKey])
                && count($packet->units[$atomKey]->atoms) === 1) {
                $atomKey .= '.'.array_key_first($packet->units[$atomKey]->atoms);
            }

            if (! $packet->hasAtom($atomKey)) {
                $this->reject('unknown support atom '.$atomKey);
            }

            $normalized[] = $atomKey;
            $evidenceKeys[] = EvidencePacket::unitKeyOf($atomKey);
        }

        $atomKeys = array_values(array_unique($normalized));

        if ($atomKeys === [] && in_array($level, ['direct', 'strong', 'interpretive', 'conflict'], true)) {
            $this->reject('a supported/conflict verdict requires support atoms');
        }

        $reason = $raw['reason_code'] ?? null;
        $reason = is_string($reason) ? mb_substr(trim($reason), 0, 64) : null;

        $answers = $raw['answers_subquestion'] ?? null;

        return new VerificationResult(
            $claim->claimKey,
            $level,
            $atomKeys,
            array_values(array_unique($evidenceKeys)),
            $reason === '' ? null : $reason,
            is_bool($answers) ? $answers : null,
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
