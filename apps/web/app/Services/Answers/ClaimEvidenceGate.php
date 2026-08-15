<?php

namespace App\Services\Answers;

use App\Models\BookAsset;
use App\Services\Answers\Providers\GeneratedClaimDraft;
use App\Services\Answers\Providers\VerificationResult;
use Illuminate\Support\Str;

/**
 * claim-gate 1.0.0 — deterministic application-side gate AFTER model
 * verification. The model verifier is NOT trusted on its own: a
 * verifier-positive claim that fails these checks becomes unsupported
 * (never displayed). No silent upgrades or downgrades to keep an
 * answer populated.
 *
 * Checks:
 * - support atoms exist in the current packet (defense in depth);
 * - non-empty support for any positive verdict;
 * - ATOMIC facts (identity/species/name/date/quantity/role/location)
 *   require DIRECT support — strong inference can never manufacture an
 *   atomic fact from association;
 * - direct atomic claims: asserted value tokens must appear in the
 *   selected atoms (same-language lexical defense; cross-language
 *   evidence is exempt and relies on the strict-entailment verifier);
 * - strong inference requires >= 2 atoms from independent source nodes
 *   unless the verifier explicitly recorded logical entailment (and
 *   the claim is not atomic);
 * - cited sources still match the current asset fingerprint.
 */
class ClaimEvidenceGate
{
    public const VERSION = 'claim-gate 1.0.0';

    public const REASON_VERIFIER_NONE = 'VERIFIER_NONE';

    public const REASON_UNKNOWN_ATOM = 'UNKNOWN_SUPPORT_ATOM';

    public const REASON_DIRECT_NOT_ESTABLISHED = 'DIRECT_NOT_ESTABLISHED';

    public const REASON_IDENTITY_REQUIRES_DIRECT = 'IDENTITY_REQUIRES_DIRECT_SUPPORT';

    public const REASON_INSUFFICIENT_INDEPENDENT = 'INSUFFICIENT_INDEPENDENT_EVIDENCE';

    public const REASON_STALE_SUPPORT = 'STALE_SUPPORT';

    public const REASON_DIRECT_STRUCTURALLY_CONFIRMED = 'DIRECT_STRUCTURALLY_CONFIRMED';

    /** Verifier reason codes that certify single-premise logical entailment. */
    private const ENTAILMENT_REASONS = ['LOGICAL_ENTAILMENT', 'LOGICALLY_ENTAILED', 'STRICT_ENTAILMENT', 'DIRECT_PARAPHRASE'];

    public function __construct(
        private readonly ClaimTypeClassifier $types,
        private readonly ResponseLanguageDetector $language,
    ) {}

    /**
     * @return array{result: 'passed'|'rejected', reason: ?string, claim_type: string}
     */
    public function evaluate(GeneratedClaimDraft $claim, VerificationResult $verdict, EvidencePacket $packet): array
    {
        $claimType = $this->types->classify($claim->text);

        if ($verdict->supportLevel === 'none') {
            return $this->rejected($claimType, self::REASON_VERIFIER_NONE);
        }

        $atoms = [];
        $units = [];

        foreach ($verdict->supportedAtomKeys as $atomKey) {
            $atom = $packet->atom($atomKey);

            if ($atom === null) {
                return $this->rejected($claimType, self::REASON_UNKNOWN_ATOM);
            }

            $atoms[] = $atom;
            $units[] = $packet->units[EvidencePacket::unitKeyOf($atomKey)];
        }

        if ($atoms === []) {
            return $this->rejected($claimType, self::REASON_DIRECT_NOT_ESTABLISHED);
        }

        // Stale defense: the cited books must still match the source
        // fingerprint the packet was built from.
        $assetShas = BookAsset::query()
            ->whereIn('id', array_unique(array_map(fn ($unit) => $unit->bookAssetId, $units)))
            ->pluck('content_sha256', 'id');

        foreach ($units as $unit) {
            if (($assetShas[$unit->bookAssetId] ?? null) !== $unit->sourceContentSha256) {
                return $this->rejected($claimType, self::REASON_STALE_SUPPORT);
            }
        }

        if ($claimType === ClaimTypeClassifier::ATOMIC_FACT) {
            $structural = $this->structuralSupport($claim->text, $atoms);

            if ($verdict->supportLevel === 'direct') {
                if ($structural === 'refuted') {
                    // The verifier certified the right UNIT but picked
                    // the wrong sentence (a known 8B weakness): scan the
                    // SIBLING atoms of the cited units with the same
                    // deterministic matcher. A sibling that explicitly
                    // predicates the value of the subject replaces the
                    // span — auditable, source-exact, still within
                    // verifier-endorsed units only.
                    $corrected = $this->findConfirmingSiblingAtom($claim->text, $verdict, $packet);

                    if ($corrected !== null) {
                        return [
                            'result' => 'passed',
                            'reason' => self::REASON_DIRECT_STRUCTURALLY_CONFIRMED,
                            'claim_type' => $claimType,
                            'atom_keys_override' => [$corrected],
                        ];
                    }

                    return $this->rejected($claimType, self::REASON_DIRECT_NOT_ESTABLISHED);
                }
            } elseif ($verdict->supportLevel === 'strong' && $structural === 'confirmed') {
                // The spec's explicit-formulation clause: a conservative
                // verifier answered "strong", but the application's own
                // deterministic check CONFIRMS the atoms explicitly
                // predicate the value of the subject (copula or
                // apposition). Auditable promotion, never silent:
                // gate_reason_code records it.
                return [
                    'result' => 'passed',
                    'reason' => self::REASON_DIRECT_STRUCTURALLY_CONFIRMED,
                    'claim_type' => $claimType,
                    'final_level_override' => 'direct',
                ];
            } elseif ($verdict->supportLevel !== 'conflict') {
                // Atomic facts are otherwise direct-or-nothing (conflict
                // stays allowed: it exposes disagreeing sources rather
                // than asserting).
                return $this->rejected($claimType, self::REASON_IDENTITY_REQUIRES_DIRECT);
            }
        }

        if ($verdict->supportLevel === 'strong') {
            $independentNodes = count(array_unique(array_map(
                fn ($unit) => $unit->bookAssetId.':'.($unit->sourceNodeId ?? spl_object_id($unit)),
                $units,
            )));

            $entailmentCertified = in_array((string) $verdict->reasonCode, self::ENTAILMENT_REASONS, true);

            if ($independentNodes < 2 && ! $entailmentCertified && $claimType !== ClaimTypeClassifier::GENERAL) {
                return $this->rejected($claimType, self::REASON_INSUFFICIENT_INDEPENDENT);
            }

            // Direct-label calibration for explicitly stated facts
            // (mechanisms, descriptions): a conservative model often
            // answers "strong" for a claim that merely RESTATES its
            // atoms. When the claim is a faithful restatement — nearly
            // all its content stems appear in the selected atoms — and
            // it is NOT a causal/interpretive synthesis, promote to
            // Fatto testuale, auditable via the gate reason. General
            // single-node strong claims that are NOT faithful
            // restatements still need independence.
            if (in_array($claimType, [ClaimTypeClassifier::GENERAL], true)) {
                if ($this->isFaithfulRestatement($claim->text, $atoms)) {
                    return [
                        'result' => 'passed',
                        'reason' => self::REASON_DIRECT_STRUCTURALLY_CONFIRMED,
                        'claim_type' => $claimType,
                        'final_level_override' => 'direct',
                    ];
                }

                if ($independentNodes < 2 && ! $entailmentCertified) {
                    return $this->rejected($claimType, self::REASON_INSUFFICIENT_INDEPENDENT);
                }
            }
        }

        return ['result' => 'passed', 'reason' => null, 'claim_type' => $claimType];
    }

    /**
     * Same-language structural defense for DIRECT atomic claims. The
     * selected atoms must not merely CONTAIN the asserted value token —
     * the value must be PREDICATED OF THE SUBJECT (copular linkage or
     * apposition). This deterministically kills the obvious classes:
     *
     *   claim "Arlen è un cane" / atom "tre cani si misero accanto ad
     *   Arlen" → the atom mentions cani but never predicates it of
     *   Arlen → rejected;
     *
     * while accepting explicit forms:
     *
     *   claim "Tomas è il figlio di Marek" / atom "Tomas, il figlio di
     *   Marek, entrò" (apposition) → accepted.
     *
     * Deliberately NOT the sole truth criterion (the strict-entailment
     * verifier is primary); cross-language evidence is exempt — its
     * lexical form legitimately differs, and the verifier plus explicit
     * source atoms carry that case.
     */
    /**
     * @return 'confirmed'|'refuted'|'unverifiable' whether the atoms
     *                                              explicitly predicate the asserted value of the subject
     */
    private function structuralSupport(string $claimText, array $atoms): string
    {
        $atomText = implode(' ', array_column($atoms, 'text'));

        if ($this->language->detect($claimText) !== $this->language->detect($atomText)) {
            // Cross-language: the lexical form legitimately differs —
            // never refute, never confirm.
            return 'unverifiable';
        }

        $claim = mb_strtolower($claimText);
        $atomsLower = mb_strtolower($atomText);

        // ── Copular claims: SUBJECT è/is (article) VALUE ────────────
        if (preg_match(
            '/([\p{L}\'\s]{2,80}?)\s+(?:è|era|sono|erano|is|was|are|were)\s+(?:un|una|uno|un\'|il|lo|la|l\'|i|gli|le|a|an|the)?\s*([\p{L}\']{3,})/u',
            $claim,
            $matches,
        )) {
            $subject = $this->lastContentWord($matches[1]);
            $value = $this->prefix($matches[2]);

            if ($subject === null) {
                return str_contains($this->normalize($atomsLower), $this->normalize($value))
                    ? 'unverifiable'
                    : 'refuted';
            }

            $s = preg_quote($subject, '/');
            $v = preg_quote($value, '/');

            // subject … copula … value (same clause), or apposition
            // "subject, … value", or reverse apposition "value …, subject".
            $predicated = preg_match('/\b'.$s.'[\p{L}\']*\b[^.;!?]{0,60}\b(?:è|era|sono|erano|is|was|are|were)\b[^.;!?]{0,50}'.$v.'/u', $atomsLower) === 1
                || preg_match('/\b'.$s.'[\p{L}\']*\s*,\s*[^,;.!?]{0,50}'.$v.'/u', $atomsLower) === 1
                || preg_match('/'.$v.'[^,;.!?]{0,50},\s*[^,;.!?]{0,20}\b'.$s.'/u', $atomsLower) === 1;

            if ($predicated) {
                return 'confirmed';
            }

            // RELATION values ("X era la vicina di Y"): the source often
            // expresses the relation non-copularly ("viveva nella casa
            // accanto"). Accept when the atom names the subject AND uses
            // a lexicon paraphrase of THAT relation. Identity values
            // outside the relation lexicon (autista, cane, …) are
            // unaffected.
            foreach (TaskContractClassifier::RELATION_LEXICON as $terms) {
                $valueInClass = false;

                foreach ($terms as $term) {
                    if (str_starts_with($this->normalize($value), $this->normalize(mb_substr($term, 0, mb_strlen($value))))
                        && TaskContractClassifier::matchesTerm($this->normalize($claim), $this->normalize($term))) {
                        $valueInClass = true;
                        break;
                    }
                }

                if (! $valueInClass) {
                    continue;
                }

                if (preg_match('/\b'.$s.'/u', $atomsLower) === 1) {
                    foreach ($terms as $term) {
                        if (TaskContractClassifier::matchesTerm($this->normalize($atomsLower), $this->normalize($term))) {
                            return 'confirmed';
                        }
                    }
                }
            }

            return 'refuted';
        }

        // ── Naming claims: value name + one more claim content word ─
        if (preg_match('/\b(?:si chiama|si chiamava|is named|is called)\s+([\p{L}\']{2,})/u', $claim, $matches)) {
            $normalizedAtoms = $this->normalize($atomsLower);

            if (! str_contains($normalizedAtoms, $this->normalize($this->prefix($matches[1])))) {
                return 'refuted';
            }

            foreach ($this->contentWords($claim) as $word) {
                if ($this->normalize($word) !== $this->normalize($matches[1])
                    && str_contains($normalizedAtoms, $this->normalize($this->prefix($word)))) {
                    return 'confirmed';
                }
            }

            return 'unverifiable';
        }

        // ── Numeric assertions: the number itself must be present ───
        if (preg_match_all('/\b(\d{3,4})\b/', $claim, $matches) && $matches[1] !== []) {
            foreach ($matches[1] as $number) {
                if (! str_contains($atomsLower, $number)) {
                    return 'refuted';
                }
            }

            return 'confirmed';
        }

        return 'unverifiable'; // nothing structurally checkable — rely on the verifier
    }

    /**
     * A claim is a faithful restatement when nearly all of its content
     * stems (>=70%, minimum 3 stems) appear in the selected atoms —
     * same language only (cross-language never promotes).
     */
    private function isFaithfulRestatement(string $claimText, array $atoms): bool
    {
        $atomText = implode(' ', array_column($atoms, 'text'));

        if ($this->language->detect($claimText) !== $this->language->detect($atomText)) {
            return false;
        }

        $stems = [];

        foreach (preg_split('/[^\p{L}\']+/u', mb_strtolower($claimText), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 5) {
                // Cap at 6 so inflected forms match their base
                // ("scendere" → "scende").
                $stems[] = mb_substr($word, 0, max(4, min(mb_strlen($word) - 1, 6)));
            }
        }

        $stems = array_unique($stems);

        if (count($stems) < 3) {
            return false;
        }

        $normalizedAtoms = $this->normalize($atomText);
        $found = 0;

        foreach ($stems as $stem) {
            if (str_contains($normalizedAtoms, $this->normalize($stem))) {
                $found++;
            }
        }

        return $found / count($stems) >= 0.7;
    }

    /**
     * Packet-wide deterministic scan for an atom that EXPLICITLY
     * predicates the claim value of its subject (copula/apposition).
     * Used only to FOCUS one gate-informed verifier retry when the model
     * answered `none` for an atomic fact that the source states plainly
     * — never to certify by itself.
     *
     * @return list<string> fully-qualified atom keys, best first
     */
    public function structurallyConfirmingAtoms(string $claimText, EvidencePacket $packet, int $max = 3): array
    {
        if ($this->types->classify($claimText) !== ClaimTypeClassifier::ATOMIC_FACT) {
            return [];
        }

        $found = [];

        foreach ($packet->units as $unitKey => $unit) {
            foreach ($unit->atoms as $atomKey => $atom) {
                if ($this->structuralSupport($claimText, [$atom]) === 'confirmed') {
                    $found[] = $unitKey.'.'.$atomKey;

                    if (count($found) >= $max) {
                        return $found;
                    }
                }
            }
        }

        return $found;
    }

    /** Scan sibling atoms of the verifier-cited units for explicit predication. */
    private function findConfirmingSiblingAtom(string $claimText, VerificationResult $verdict, EvidencePacket $packet): ?string
    {
        foreach (array_unique($verdict->supportedEvidenceKeys) as $unitKey) {
            $unit = $packet->units[$unitKey] ?? null;

            if ($unit === null) {
                continue;
            }

            foreach ($unit->atoms as $atomKey => $atom) {
                $qualified = $unitKey.'.'.$atomKey;

                if (in_array($qualified, $verdict->supportedAtomKeys, true)) {
                    continue;
                }

                if ($this->structuralSupport($claimText, [$atom]) === 'confirmed') {
                    return $qualified;
                }
            }
        }

        return null;
    }

    private function lastContentWord(string $phrase): ?string
    {
        $words = preg_split('/[^\p{L}\']+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = ['che', 'chi', 'della', 'del', 'dello', 'delle', 'dei', 'di', 'la', 'il', 'lo', 'le', 'gli', 'una', 'uno', 'un', 'the', 'who', 'that', 'his', 'her', 'sua', 'suo'];

        for ($i = count($words) - 1; $i >= 0; $i--) {
            $word = mb_strtolower($words[$i]);

            if (mb_strlen($word) >= 3 && ! in_array($word, $stopwords, true)) {
                return $this->prefix($word);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function contentWords(string $text): array
    {
        $words = preg_split('/[^\p{L}\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn ($word) => mb_strlen($word) >= 5));
    }

    /** Diacritic-preserving stem prefix for tolerant matching (cani/cane). */
    private function prefix(string $word): string
    {
        $word = mb_strtolower($word);

        return mb_substr($word, 0, max(3, min(mb_strlen($word) - 1, 7)));
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }

    private function rejected(string $claimType, string $reason): array
    {
        return ['result' => 'rejected', 'reason' => $reason, 'claim_type' => $claimType];
    }
}
