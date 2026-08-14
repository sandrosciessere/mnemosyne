<?php

namespace App\Services\Answers;

use App\Services\Answers\Providers\GeneratedClaimDraft;
use App\Services\Answers\Providers\VerificationResult;
use Illuminate\Support\Str;

/**
 * claim-relevance-gate 1.0.0 — deterministic application gate that
 * asks the question ClaimEvidenceGate does not:
 *
 *   "Does this claim actually ANSWER its assigned subquestion?"
 *
 * A claim must not survive merely because it is true and sourced.
 * Checks are driven by the TaskContract (answer shape, entity, relation,
 * location dimension) plus a generic anchor-overlap floor; the
 * verifier's advisory `answers_subquestion` bit can only REJECT, never
 * accept on its own. Runs entirely deterministically — no model call.
 */
class ClaimRelevanceGate
{
    public const VERSION = 'claim-relevance-gate 1.0.0';

    public const REASON_NON_RESPONSIVE = 'NON_RESPONSIVE';

    public const REASON_WRONG_ENTITY = 'WRONG_ENTITY';

    public const REASON_MISSING_RELATION = 'MISSING_RELATION';

    public const REASON_MISSING_LOCATION = 'MISSING_LOCATION_DIMENSION';

    public const REASON_WRONG_SHAPE = 'WRONG_ANSWER_SHAPE';

    public const REASON_VERIFIER_NON_RESPONSIVE = 'VERIFIER_MARKED_NON_RESPONSIVE';

    private const LOCATION_MARKERS = [
        'capitolo', 'parte', 'pagina', 'sezione', 'scena', 'episodio', 'momento',
        'inizio', 'metà', 'meta', 'fine', 'finale', 'prologo', 'epilogo',
        'quando', 'dopo', 'prima', 'durante', 'nel punto', 'passaggio',
        'chapter', 'beginning', 'middle', 'end', 'scene', 'moment', 'when', 'after', 'before',
    ];

    /**
     * @return array{result: 'passed'|'rejected', reason: ?string}
     */
    public function evaluate(GeneratedClaimDraft $claim, VerificationResult $verdict, TaskContract $contract, ?EvidencePacket $packet = null): array
    {
        // Model-assisted rejection (advisory bit, only trusted to say NO).
        if ($verdict->answersSubquestion === false) {
            return ['result' => 'rejected', 'reason' => self::REASON_VERIFIER_NON_RESPONSIVE];
        }

        $claimNorm = $this->normalize($claim->text);
        $shapeChecked = false;

        // ── Shape-specific deterministic checks ─────────────────────
        switch ($contract->answerShape) {
            case TaskContract::SHAPE_LOCATION:
                // "A che punto…" needs a narrative point, not reasons.
                if (! $this->containsAny($claimNorm, self::LOCATION_MARKERS)) {
                    return ['result' => 'rejected', 'reason' => self::REASON_MISSING_LOCATION];
                }
                $shapeChecked = true;
                break;

            case TaskContract::SHAPE_YES_NO:
            case TaskContract::SHAPE_LIST:
            case TaskContract::SHAPE_SCALAR:
                // Relationship-driven answers must actually speak about
                // the requested relation (or its state opposites).
                if ($contract->relationshipType !== null) {
                    $terms = TaskContractClassifier::RELATION_LEXICON[$contract->relationshipType] ?? [];
                    $stateTerms = ['mort', 'viv', 'vedov', 'defunt', 'dead', 'alive', 'widow'];

                    if (! $this->containsAny($claimNorm, array_merge($terms, $stateTerms))) {
                        return ['result' => 'rejected', 'reason' => self::REASON_MISSING_RELATION];
                    }
                    $shapeChecked = true;
                }
                break;

            case TaskContract::SHAPE_DESCRIPTION:
                // The claim must be about the described target: require
                // the head entity of the subquestion in the claim.
                $head = $this->headEntity($contract);

                if ($head !== null) {
                    if (! str_contains($claimNorm, $this->stem($head))) {
                        return ['result' => 'rejected', 'reason' => self::REASON_WRONG_ENTITY];
                    }
                    $shapeChecked = true;
                }
                break;
        }

        // ── Generic anchor-overlap floor ────────────────────────────
        // Applied only when no shape-specific check validated the claim
        // (shape checks are the stronger, cross-language-safe filters).
        // The claim must share at least one content anchor with its
        // subquestion: a claim about a different subject entirely is
        // non-responsive no matter how well sourced.
        if (! $shapeChecked && $contract->anchorTerms !== []) {
            // The anchor may live in the claim itself OR in its
            // supporting atoms: a mechanism claim legitimately restates
            // HOW something works without renaming the subject, but its
            // evidence is anchored to it. A claim whose text AND
            // evidence never touch the question's subject is
            // non-responsive.
            $unitText = '';

            if ($packet !== null) {
                foreach (array_unique($verdict->supportedEvidenceKeys) as $unitKey) {
                    $unitText .= ' '.($packet->units[$unitKey]->text ?? '');
                }
            }

            $searchable = $claimNorm.' '.$this->normalize($unitText);
            $overlap = 0;

            foreach ($contract->anchorTerms as $anchor) {
                if (str_contains($searchable, $this->stem($anchor))) {
                    $overlap++;
                }
            }

            if ($overlap === 0) {
                return ['result' => 'rejected', 'reason' => self::REASON_NON_RESPONSIVE];
            }
        }

        return ['result' => 'passed', 'reason' => null];
    }

    /** Head entity of a description task ("Descrivi il violino" → violino). */
    private function headEntity(TaskContract $contract): ?string
    {
        if (preg_match('/\b(?:descriv\w*|describe)\s+(?:il|lo|la|i|gli|le|un|una|the|a|an)?\s*([\p{L}\']{3,})/iu', $contract->question, $matches)) {
            return $matches[1];
        }

        return $contract->anchorTerms[0] ?? null;
    }

    /** @param list<string> $needles word-boundary-aware (see TaskContractClassifier::matchesTerm) */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (TaskContractClassifier::matchesTerm($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function stem(string $word): string
    {
        $word = $this->normalize($word);

        return mb_substr($word, 0, max(3, min(mb_strlen($word) - 1, 7)));
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }
}
