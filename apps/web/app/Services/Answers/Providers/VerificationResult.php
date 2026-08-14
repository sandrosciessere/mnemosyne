<?php

namespace App\Services\Answers\Providers;

/**
 * Normalized independent verifier judgment for one claim. The verifier
 * selects exact SENTENCE ATOMS (E3.S2) from the packet — validated;
 * unit-level evidence keys are derived from the atoms. It may select
 * different/better atoms than the generator proposed but can never
 * invent identifiers.
 */
class VerificationResult
{
    /**
     * @param  list<string>  $supportedAtomKeys  e.g. ["E3.S2", "E8.S1"]
     * @param  list<string>  $supportedEvidenceKeys  derived unit keys, e.g. ["E3", "E8"]
     */
    public function __construct(
        public readonly string $claimKey,
        public readonly string $supportLevel,
        public readonly array $supportedAtomKeys,
        public readonly array $supportedEvidenceKeys,
        public readonly ?string $reasonCode,
        /** Advisory: whether the claim answers its subquestion. Only a
         *  `false` is acted on (rejection); `true` is never trusted alone. */
        public readonly ?bool $answersSubquestion = null,
    ) {}
}
