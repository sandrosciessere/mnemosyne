<?php

namespace App\Services\Answers;

/**
 * query-reformulator 1.0.0 — deterministic bounded query variants per
 * subquestion (max 4). This is retrieval assistance ONLY: variants
 * widen candidate recall for local facts whose source wording differs
 * from the question; they are never evidence and never weaken
 * verification.
 *
 * Variants:
 *  1. the original subquestion;
 *  2. normalized content query (interrogative scaffolding stripped,
 *     entities/negation/state terms preserved);
 *  3. relation-aware variant (relation lexicon terms + entities);
 *  4. state-opposite variant for binary state questions
 *     (vivo↔morto, presente↔assente, sposato↔vedovo).
 */
class QueryReformulator
{
    public const VERSION = 'query-reformulator 1.0.0';

    /** Semantic words that must survive normalization despite length. */
    private const PRESERVE = ['non', 'mai', 'sempre', 'più', 'prima', 'dopo', 'vivo', 'viva', 'vivi', 'morto', 'morta', 'senza', 'alive', 'dead', 'not', 'never', 'always'];

    private const STATE_OPPOSITES = [
        'vivo' => 'morto', 'viva' => 'morta', 'in vita' => 'morta',
        'presente' => 'assente', 'vivente' => 'defunto',
        'sposato' => 'vedovo', 'sposata' => 'vedova',
        'alive' => 'dead', 'present' => 'absent', 'married' => 'widowed',
    ];

    private const SCAFFOLDING = [
        'come si chiama', 'come funziona', 'come funzionano', 'come mai', 'che cosa', 'a che punto',
        'chi sono', 'chi è', 'chi era', 'quali sono', 'qual è', 'quanti', 'quante',
        'fammi', 'dammi', 'dimmi', 'elenca', 'elencami', 'descrivi', 'descrivimi', 'spiega', 'spiegami',
        'si trova', 'c\'è', 'ci sono', 'mi puoi dire', 'puoi dirmi',
        'come', 'cosa', 'chi', 'dove', 'quando', 'perché', 'perche', 'quale', 'quali',
        'what', 'who', 'where', 'when', 'why', 'how', 'which', 'does', 'do', 'is', 'are', 'list', 'describe', 'explain', 'tell me',
    ];

    /**
     * @return list<string> 1..4 unique non-empty query variants
     */
    public function variants(TaskContract $contract): array
    {
        $original = trim($contract->question);
        $variants = [$original];

        $normalized = $this->normalize($original);

        if ($normalized !== '' && mb_strtolower($normalized) !== mb_strtolower($original)) {
            $variants[] = $normalized;
        }

        if ($contract->relationshipType !== null) {
            $relationTerms = array_slice(
                TaskContractClassifier::RELATION_LEXICON[$contract->relationshipType] ?? [],
                0,
                4,
            );
            $entities = $this->properNouns($original);
            $relationVariant = trim(implode(' ', array_unique(array_merge($entities, $relationTerms))));

            if ($relationVariant !== '') {
                $variants[] = $relationVariant;
            }
        }

        if ($contract->answerShape === TaskContract::SHAPE_YES_NO) {
            // The original keeps multiword states ("in vita") that
            // normalization may lose.
            $opposite = $this->stateOpposite($original) ?? $this->stateOpposite($normalized);

            if ($opposite !== null) {
                $variants[] = $opposite;
            }
        }

        $unique = [];

        foreach ($variants as $variant) {
            $key = mb_strtolower($variant);

            if (! isset($unique[$key]) && trim($variant) !== '') {
                $unique[$key] = $variant;
            }
        }

        return array_slice(array_values($unique), 0, (int) config('mnemosyne.answers.retrieval.max_query_variants', 4));
    }

    /** Strip interrogative scaffolding; keep entities, negation, states. */
    public function normalize(string $question): string
    {
        $text = ' '.mb_strtolower(trim($question, " \t?!.")).' ';

        foreach (self::SCAFFOLDING as $phrase) {
            $text = preg_replace('/\s'.preg_quote($phrase, '/').'\s/u', ' ', $text) ?? $text;
        }

        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];

        foreach ($words as $word) {
            $bare = trim($word, "',.;:!?");

            if (mb_strlen($bare) >= 3 || in_array($bare, self::PRESERVE, true)) {
                $kept[] = $bare;
            }
        }

        return implode(' ', $kept);
    }

    /** @return list<string> capitalized tokens from the original question */
    private function properNouns(string $question): array
    {
        preg_match_all('/(?<!^)(?<![.!?]\s)\b\p{Lu}\p{Ll}{2,}\b/u', ' '.$question, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function stateOpposite(string $query): ?string
    {
        $lower = mb_strtolower($query);

        foreach (self::STATE_OPPOSITES as $state => $opposite) {
            if (str_contains($lower, $state)) {
                return trim(str_replace($state, $opposite, $lower));
            }

            if (str_contains($lower, $opposite)) {
                return trim(str_replace($opposite, $state, $lower));
            }
        }

        return null;
    }
}
