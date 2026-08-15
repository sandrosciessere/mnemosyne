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
    /**
     * 1.1.0: relation PERSPECTIVE transforms — the same fact is often
     * narrated from the other side of the relation ("la madre dei
     * bambini" instead of "la moglie di Atticus"; "il figlio di X"
     * instead of "il padre di Y"). These are SEARCH HYPOTHESES tagged
     * retrieval_hypothesis: they widen candidate recall and never enter
     * evidence provenance.
     */
    public const VERSION = 'query-reformulator 1.1.0';

    /**
     * Bounded relation perspective lexicon (it/en). For a relation
     * class R asked about entity X, the alternative perspectives are
     * how a source may express the SAME relation without R's word.
     */
    private const RELATION_PERSPECTIVES = [
        'spouse' => ['madre dei figli', 'padre dei figli', 'sua moglie morì', 'suo marito morì', 'vedovo', 'vedova', 'la moglie di', 'il marito di', 'sposato con', 'sposata con', 'mother of his children', 'his late wife', 'her late husband', 'widower', 'widow'],
        'parent' => ['sua madre', 'suo padre', 'i suoi genitori', 'figlio di', 'figlia di', 'his mother', 'his father', 'her mother', 'son of', 'daughter of'],
        'child' => ['suo figlio', 'sua figlia', 'i suoi figli', 'il padre di', 'la madre di', 'his son', 'her daughter', 'father of', 'mother of'],
        'sibling' => ['suo fratello', 'sua sorella', 'i suoi fratelli', 'his brother', 'her sister'],
        'neighbor' => ['casa accanto', 'abita accanto', 'vive accanto', 'porta accanto', 'casa confinante', 'la casa vicina', 'i vicini di casa', 'dall\'altra parte della strada', 'next door', 'lives beside', 'house next to', 'across the street', 'neighbour'],
        'friend' => ['il suo amico', 'la sua amica', 'i suoi amici', 'amicizia con', 'his friend', 'her friend'],
        'enemy' => ['il suo nemico', 'rivale di', 'contro di lui', 'his enemy', 'rival of'],
        'teacher' => ['il suo maestro', 'la sua maestra', 'il suo insegnante', 'suo allievo', 'his teacher', 'her teacher', 'his pupil'],
        'owner' => ['appartiene a', 'di sua proprietà', 'il padrone di', 'belongs to', 'owner of'],
    ];

    /** Semantic words that must survive normalization despite length. */
    private const PRESERVE = ['non', 'mai', 'sempre', 'più', 'prima', 'dopo', 'vivo', 'viva', 'vivi', 'morto', 'morta', 'senza', 'alive', 'dead', 'not', 'never', 'always'];

    private const STATE_OPPOSITES = [
        'vivo' => 'morto', 'viva' => 'morta', 'in vita' => 'morta morì defunta',
        'presente' => 'assente', 'vivente' => 'defunto',
        'sposato' => 'vedovo', 'sposata' => 'vedova',
        'alive' => 'dead died', 'present' => 'absent', 'married' => 'widowed',
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

        if ($contract->relationshipType !== null && isset(self::RELATION_PERSPECTIVES[$contract->relationshipType])) {
            // Perspective variant: entities + how the OTHER side of the
            // relation is typically narrated. Tagged as a retrieval
            // hypothesis by the caller; never evidence.
            $entities = $this->properNouns($original);
            $perspectives = array_slice(self::RELATION_PERSPECTIVES[$contract->relationshipType], 0, 5);
            $perspectiveVariant = trim(implode(' ', array_merge($entities, $perspectives)));

            if ($perspectiveVariant !== '') {
                $variants[] = $perspectiveVariant;
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

    /**
     * Focused-expansion queries for a subquestion whose first packet
     * held no certifiable evidence: strictly MORE informative than
     * "same query, larger top-K" — the full perspective lexicon of the
     * relation (not the first 5), all state expressions, and lexical
     * hints harvested from the promising source regions of the first
     * pass. Bounded (max 4), persisted by the caller.
     *
     * @param  list<string>  $regionHints  content terms from the best first-pass units
     * @return list<string>
     */
    public function expansionVariants(TaskContract $contract, array $regionHints = []): array
    {
        $original = trim($contract->question);
        $entities = $this->properNouns($original);
        $variants = [];

        if ($contract->relationshipType !== null) {
            $lexicon = TaskContractClassifier::RELATION_LEXICON[$contract->relationshipType] ?? [];
            $perspectives = self::RELATION_PERSPECTIVES[$contract->relationshipType] ?? [];

            // Split the perspective lexicon into two halves so each
            // query stays focused (dense embeddings dislike 15-term bags).
            $half = (int) ceil(count($perspectives) / 2);
            $variants[] = trim(implode(' ', array_merge($entities, array_slice($perspectives, 0, $half))));
            $variants[] = trim(implode(' ', array_merge($entities, array_slice($perspectives, $half), array_slice($lexicon, 4, 4))));
        }

        if ($contract->answerShape === TaskContract::SHAPE_YES_NO) {
            $states = [];

            foreach (self::STATE_OPPOSITES as $state => $opposite) {
                if (str_contains(mb_strtolower($original), $state) || str_contains(mb_strtolower($original), $opposite)) {
                    $states[] = $state.' '.$opposite;
                }
            }

            if ($states !== []) {
                $variants[] = trim(implode(' ', array_merge($entities, $states)));
            }
        }

        if ($regionHints !== []) {
            $variants[] = trim(implode(' ', array_merge($entities, array_slice($regionHints, 0, 6))));
        }

        // Always include the normalized content query with the entities
        // repeated (lexical weight on the who).
        $normalized = $this->normalize($original);

        if ($normalized !== '') {
            $variants[] = trim(implode(' ', $entities).' '.$normalized);
        }

        $unique = [];

        foreach ($variants as $variant) {
            $key = mb_strtolower($variant);

            if (trim($variant) !== '' && ! isset($unique[$key])) {
                $unique[$key] = $variant;
            }
        }

        return array_slice(array_values($unique), 0, 4);
    }

    /**
     * Stems of the relation lexicon + perspectives + state terms for a
     * contract — used by the packet builder to recognise anchor-bearing
     * units on the focused expansion pass.
     *
     * @return list<string>
     */
    public function relationAnchorStems(TaskContract $contract): array
    {
        $terms = [];

        if ($contract->relationshipType !== null) {
            $terms = array_merge(
                TaskContractClassifier::RELATION_LEXICON[$contract->relationshipType] ?? [],
                self::RELATION_PERSPECTIVES[$contract->relationshipType] ?? [],
            );
        }

        if ($contract->answerShape === TaskContract::SHAPE_YES_NO) {
            foreach (self::STATE_OPPOSITES as $state => $opposite) {
                $terms[] = $state;
                foreach (explode(' ', $opposite) as $word) {
                    $terms[] = $word;
                }
            }
        }

        $stems = [];

        foreach ($terms as $term) {
            foreach (preg_split('/\s+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) >= 4) {
                    $stems[] = mb_substr($word, 0, max(4, min(mb_strlen($word) - 1, 7)));
                }
            }
        }

        return array_values(array_unique($stems));
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
        // Capitalized tokens that are NOT sentence-initial interrogatives
        // ("Chi", "Come", "Who"): the first word of a question is
        // capitalized by convention, so it counts only when it is not a
        // known interrogative/function word.
        preg_match_all('/\b\p{Lu}\p{Ll}{2,}\b/u', $question, $matches);
        $stop = ['chi', 'che', 'come', 'cosa', 'dove', 'quando', 'quale', 'quali', 'perché', 'perche', 'quanti', 'quante', 'descrivi', 'elenca', 'fammi', 'dammi', 'dimmi', 'spiega', 'who', 'what', 'where', 'when', 'which', 'why', 'how', 'does', 'list', 'describe', 'tell', 'the', 'una', 'uno', 'gli', 'nel', 'nella', 'del', 'della'];

        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            fn ($w) => ! in_array(mb_strtolower($w), $stop, true),
        )));
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
