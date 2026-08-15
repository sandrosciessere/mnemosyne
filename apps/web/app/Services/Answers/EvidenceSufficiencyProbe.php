<?php

namespace App\Services\Answers;

use Illuminate\Support\Str;

/**
 * evidence-sufficiency-probe 1.0.0 — cheap, deterministic, task-aware
 * check run BEFORE generation: does the packet contain any unit that
 * plausibly carries evidence for THIS subquestion's information need?
 *
 * "24 units" is occupancy, not recall. The probe asks, per supported
 * subquestion, whether some packet unit both mentions a question
 * entity/anchor AND touches the asked dimension (relation lexicon or
 * its perspectives, state terms, mechanism/description anchors). A
 * negative probe triggers the single bounded focused expansion BEFORE
 * an expensive generation+verification cycle is spent discovering the
 * same thing. The probe never asserts sufficiency — the strict
 * verifier still decides — it only detects LIKELY insufficiency early.
 */
class EvidenceSufficiencyProbe
{
    public const VERSION = 'evidence-sufficiency-probe 1.0.0';

    private const STATE_TERMS = ['mort', 'viv', 'vedov', 'defunt', 'dead', 'alive', 'widow', 'died', 'morì'];

    /**
     * @return array{likely_sufficient: bool, matched_units: list<string>, reason: string}
     */
    public function probe(TaskContract $contract, EvidencePacket $packet): array
    {
        $anchors = array_map(fn ($a) => $this->stem($a), $contract->anchorTerms);
        $entities = $this->properNouns($contract->question);
        $dimensionTerms = [];

        if ($contract->relationshipType !== null) {
            $dimensionTerms = array_merge(
                TaskContractClassifier::RELATION_LEXICON[$contract->relationshipType] ?? [],
                $contract->answerShape === TaskContract::SHAPE_YES_NO ? self::STATE_TERMS : [],
            );
        }

        $matched = [];

        foreach ($packet->units as $key => $unit) {
            if (($unit->retrievalMeta['subquestion'] ?? $contract->subquestionKey) !== $contract->subquestionKey) {
                continue;
            }

            $text = $this->normalize($unit->text);

            $entityHit = $entities === [] || $this->anyStem($text, $entities);
            $anchorHit = $anchors === [] || $this->anyContains($text, $anchors);

            if ($dimensionTerms !== []) {
                // Relationship / state tasks: entity AND dimension.
                if ($entityHit && $this->anyContains($text, array_map(fn ($t) => $this->normalize($t), $dimensionTerms))) {
                    $matched[] = $key;
                }
            } elseif ($entityHit && $anchorHit) {
                $matched[] = $key;
            }
        }

        return [
            'likely_sufficient' => $matched !== [],
            'matched_units' => $matched,
            'reason' => $matched === []
                ? ($dimensionTerms !== [] ? 'NO_UNIT_WITH_ENTITY_AND_RELATION' : 'NO_UNIT_WITH_ENTITY_AND_ANCHOR')
                : 'CANDIDATE_EVIDENCE_PRESENT',
        ];
    }

    /**
     * Content terms harvested from the units that mention the question
     * entities (used to focus the expansion on the promising regions).
     *
     * @return list<string>
     */
    public function regionHints(TaskContract $contract, EvidencePacket $packet, int $max = 6): array
    {
        $entities = $this->properNouns($contract->question);
        $stopwords = array_flip(['della', 'delle', 'degli', 'dello', 'nella', 'nelle', 'quando', 'anche', 'perché', 'come', 'sono', 'erano', 'aveva', 'avevano', 'essere', 'stato', 'stata', 'which', 'their', 'there', 'about', 'would', 'could']);
        $counts = [];

        foreach ($packet->units as $unit) {
            $text = $this->normalize($unit->text);

            if ($entities !== [] && ! $this->anyStem($text, $entities)) {
                continue;
            }

            foreach (preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) >= 6 && ! isset($stopwords[$word]) && ! $this->anyStem($word, $entities)) {
                    $counts[$word] = ($counts[$word] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, $max);
    }

    /** @return list<string> normalized capitalized tokens */
    private function properNouns(string $question): array
    {
        preg_match_all('/\b\p{Lu}\p{Ll}{2,}\b/u', $question, $matches);
        $stop = ['chi', 'che', 'come', 'cosa', 'dove', 'quando', 'quale', 'quali', 'perché', 'perche', 'quanti', 'quante', 'descrivi', 'elenca', 'fammi', 'dammi', 'dimmi', 'spiega', 'who', 'what', 'where', 'when', 'which', 'why', 'how', 'does', 'list', 'describe', 'tell', 'the', 'una', 'uno', 'gli', 'nel', 'nella', 'del', 'della'];

        return array_values(array_unique(array_map(
            fn ($w) => $this->stem($w),
            array_filter($matches[0] ?? [], fn ($w) => ! in_array(mb_strtolower($w), $stop, true)),
        )));
    }

    /** @param list<string> $stems */
    private function anyStem(string $text, array $stems): bool
    {
        foreach ($stems as $stem) {
            if ($stem !== '' && str_contains($text, $stem)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $needles */
    private function anyContains(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
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
