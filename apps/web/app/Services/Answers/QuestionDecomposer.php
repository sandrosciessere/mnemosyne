<?php

namespace App\Services\Answers;

/**
 * question-decomposer 1.0.0 — bounded deterministic decomposition of
 * clearly multi-part questions ("che auto guida X e quindi chi prende
 * la sua identità?"). Splits on interrogative-clause conjunctions and
 * question-mark boundaries; max 4 subquestions; single-part questions
 * pass through untouched. No model call, fully auditable.
 */
class QuestionDecomposer
{
    public const VERSION = 'question-decomposer 1.0.0';

    public const MAX_SUBQUESTIONS = 4;

    /**
     * @return list<array{key: string, text: string}> one entry for a
     *                                                simple question, 2..4 for a compound one
     */
    public function decompose(string $question): array
    {
        $normalized = trim($question);

        // 1) split on '?' when more text follows (multi-sentence).
        $parts = [];

        foreach (preg_split('/(?<=\?)\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [$normalized] as $sentence) {
            // 2) split each sentence on a conjunction that introduces a
            // NEW interrogative clause ("e quindi chi...", "e che...",
            // "and who...").
            $clauses = preg_split(
                '/\s+(?:e|ed|and)\s+(?=(?:quindi\s+|poi\s+|anche\s+|also\s+|then\s+)?'
                .'(?:chi|che|quale|quali|come|dove|quando|perch[eé]|cosa|qual|in che|per quale'
                .'|who|what|which|where|when|why|how|whose)\b)/iu',
                $sentence,
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [$sentence];

            foreach ($clauses as $clause) {
                $clause = trim($clause);

                if ($clause !== '' && mb_strlen($clause) >= 8) {
                    $parts[] = $clause;
                }
            }
        }

        if (count($parts) < 2) {
            return [['key' => 'SQ1', 'text' => $normalized]];
        }

        $parts = array_slice($parts, 0, self::MAX_SUBQUESTIONS);

        return array_values(array_map(
            fn ($index, $text) => ['key' => 'SQ'.($index + 1), 'text' => $text],
            array_keys($parts),
            $parts,
        ));
    }
}
