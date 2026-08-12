<?php

namespace App\Services\Answers\Providers;

use App\Services\Answers\EvidencePacket;

/**
 * Deterministic verifier double. Default behavior (unscripted): confirm
 * the generator's proposal at `direct` level — convenient for pipeline
 * tests that focus elsewhere. Scripted outputs run through the REAL
 * VerifierOutputValidator; Throwables simulate provider failures.
 * Fails closed in production.
 */
class FakeVerifierProvider implements VerifierProvider
{
    /** @var array<string, array|\Throwable> keyed by claim_key ('*' = any) */
    private array $script = [];

    /** @var list<string> claim keys in verification order */
    public array $calls = [];

    public function __construct(private readonly VerifierOutputValidator $validator)
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('FakeVerifierProvider must never run in production.');
        }
    }

    public function scriptFor(string $claimKey, array|\Throwable $output): void
    {
        $this->script[$claimKey] = $output;
    }

    public function verify(string $question, EvidencePacket $packet, GeneratedClaimDraft $claim): VerificationResult
    {
        $this->calls[] = $claim->claimKey;

        $scripted = $this->script[$claim->claimKey] ?? $this->script['*'] ?? null;

        if ($scripted instanceof \Throwable) {
            throw $scripted;
        }

        if ($scripted === null) {
            $scripted = [
                'claim_key' => $claim->claimKey,
                'support_level' => 'direct',
                'supported_atom_keys' => array_map(fn ($key) => $key.'.S1', $claim->evidenceKeys),
                'reason_code' => 'DIRECTLY_STATED',
            ];
        }

        return $this->validator->validate($scripted, $packet, $claim);
    }

    public function identity(): ProviderIdentity
    {
        return new ProviderIdentity('fake', 'deterministic-test-verifier', 'test');
    }
}
