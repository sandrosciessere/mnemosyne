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
            // Atomic facts are direct-or-nothing (conflict stays allowed:
            // it exposes disagreeing sources rather than asserting).
            if (! in_array($verdict->supportLevel, ['direct', 'conflict'], true)) {
                return $this->rejected($claimType, self::REASON_IDENTITY_REQUIRES_DIRECT);
            }

            if ($verdict->supportLevel === 'direct' && ! $this->valueTokensSupported($claim->text, $atoms)) {
                return $this->rejected($claimType, self::REASON_DIRECT_NOT_ESTABLISHED);
            }
        }

        if ($verdict->supportLevel === 'strong') {
            $independentNodes = count(array_unique(array_map(
                fn ($unit) => $unit->bookAssetId.':'.($unit->sourceNodeId ?? spl_object_id($unit)),
                $units,
            )));

            $entailmentCertified = in_array((string) $verdict->reasonCode, self::ENTAILMENT_REASONS, true);

            if ($independentNodes < 2 && ! $entailmentCertified) {
                return $this->rejected($claimType, self::REASON_INSUFFICIENT_INDEPENDENT);
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
    private function valueTokensSupported(string $claimText, array $atoms): bool
    {
        $atomText = implode(' ', array_column($atoms, 'text'));

        if ($this->language->detect($claimText) !== $this->language->detect($atomText)) {
            return true;
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

            if ($subject !== null) {
                $s = preg_quote($subject, '/');
                $v = preg_quote($value, '/');

                // subject … copula … value (same clause), or apposition
                // "subject, … value", or reverse apposition
                // "value …, subject".
                $predicated = preg_match('/\b'.$s.'[\p{L}\']*\b[^.;!?]{0,60}\b(?:è|era|sono|erano|is|was|are|were)\b[^.;!?]{0,50}'.$v.'/u', $atomsLower) === 1
                    || preg_match('/\b'.$s.'[\p{L}\']*\s*,\s*[^,;.!?]{0,50}'.$v.'/u', $atomsLower) === 1
                    || preg_match('/'.$v.'[^,;.!?]{0,50},\s*[^,;.!?]{0,20}\b'.$s.'/u', $atomsLower) === 1;

                if (! $predicated) {
                    return false;
                }
            } elseif (! str_contains($this->normalize($atomsLower), $this->normalize($value))) {
                return false;
            }

            return true;
        }

        // ── Naming claims: value name + one more claim content word ─
        if (preg_match('/\b(?:si chiama|si chiamava|is named|is called)\s+([\p{L}\']{2,})/u', $claim, $matches)) {
            $normalizedAtoms = $this->normalize($atomsLower);

            if (! str_contains($normalizedAtoms, $this->normalize($this->prefix($matches[1])))) {
                return false;
            }

            foreach ($this->contentWords($claim) as $word) {
                if ($this->normalize($word) !== $this->normalize($matches[1])
                    && str_contains($normalizedAtoms, $this->normalize($this->prefix($word)))) {
                    return true;
                }
            }

            return false;
        }

        // ── Numeric assertions: the number itself must be present ───
        if (preg_match_all('/\b(\d{3,4})\b/', $claim, $matches)) {
            foreach ($matches[1] as $number) {
                if (! str_contains($atomsLower, $number)) {
                    return false;
                }
            }
        }

        return true; // nothing structurally checkable — rely on the verifier
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
