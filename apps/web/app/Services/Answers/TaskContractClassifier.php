<?php

namespace App\Services\Answers;

/**
 * Deterministic per-subquestion task classification (task-contract
 * 1.0.0). Rule-based and versioned like the canonical intent
 * classifier — the contract is MORE specific than the canonical
 * intent and decides answer shape, coverage requirement and M3
 * capability BEFORE any model call. First matching rule wins; rules
 * are ordered by specificity. Multilingual (it primary, en secondary).
 */
class TaskContractClassifier
{
    public const VERSION = TaskContract::VERSION;

    /** @var array<string, list<string>> relation → query/relevance lexicon */
    public const RELATION_LEXICON = [
        'spouse' => ['moglie', 'marito', 'sposa', 'sposo', 'sposat', 'matrimonio', 'coniuge', 'vedov', 'wife', 'husband', 'married', 'widow'],
        'parent' => ['madre', 'padre', 'genitor', 'mamma', 'papà', 'mother', 'father', 'parent'],
        'child' => ['figlio', 'figlia', 'figli', 'son', 'daughter', 'children'],
        'sibling' => ['fratello', 'sorella', 'fratelli', 'brother', 'sister', 'sibling'],
        'neighbor' => ['vicin', 'accanto', 'casa accanto', 'porta accanto', 'abita vicino', 'neighbor', 'next door', 'nearby'],
        'friend' => ['amic', 'amicizia', 'friend'],
        'enemy' => ['nemic', 'rival', 'avversari', 'enemy'],
        'teacher' => ['maestro', 'maestra', 'insegnante', 'allievo', 'allieva', 'teacher', 'student'],
        'owner' => ['propriet', 'padron', 'possiede', 'appartiene', 'owner', 'belongs'],
    ];

    private const ITALIAN_NUMBERS = [
        'uno' => 1, 'due' => 2, 'tre' => 3, 'quattro' => 4, 'cinque' => 5,
        'sei' => 6, 'sette' => 7, 'otto' => 8, 'nove' => 9, 'dieci' => 10,
        'venti' => 20, 'five' => 5, 'ten' => 10,
    ];

    public function classify(string $subquestionKey, string $question): TaskContract
    {
        $q = mb_strtolower(trim($question));
        $anchors = $this->anchorTerms($q);

        // ── TOP-N global ranking ────────────────────────────────────
        if (preg_match('/\b(principal|più important|piu important|main|most important|top)\w*/u', $q)
            && preg_match('/\b(elenc|lista|list|fammi|dammi|quali sono|chi sono)\w*/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::TOP_N_RANKING, TaskContract::SHAPE_TOP_N_LIST,
                entityType: str_contains($q, 'personagg') || str_contains($q, 'character') ? 'person' : null,
                count: $this->requestedCount($q), coverage: 'global', relationship: null, ranking: true,
                supported: false, notice: 'LIMITED_GLOBAL_RANKING_SUPPORT', anchors: $anchors);
        }

        // ── Global summary ──────────────────────────────────────────
        if (preg_match('/\b(riassum|riassunto|sintesi|la trama|di cosa parla|summariz|summary|plot of)\w*/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::GLOBAL_SUMMARY, TaskContract::SHAPE_EXPLANATION,
                entityType: null, count: null, coverage: 'global', relationship: null, ranking: false,
                supported: false, notice: 'LIMITED_GLOBAL_SUMMARY_SUPPORT', anchors: $anchors);
        }

        // ── Temporal evolution / longitudinal invariance ────────────
        if (preg_match('/\b(rimane sempre|resta sempre|cambia nel corso|si evolve|come evolve|come cambia|evoluzione|nel corso (del|della|dell)|attraverso (il|tutto)|arco narrativo|dall\'inizio alla fine|throughout|over the course|stays the same|always the same)\b/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::TEMPORAL_EVOLUTION, TaskContract::SHAPE_EVOLUTION,
                entityType: null, count: null, coverage: 'longitudinal', relationship: null, ranking: false,
                supported: false, notice: 'LIMITED_LONGITUDINAL_SUPPORT', anchors: $anchors);
        }

        // ── Identity / reveal ───────────────────────────────────────
        if (preg_match('/\b(a che punto|in che punto|quando)\b.*\b(si capisce|si scopre|si rivela|viene rivelat|si intuisce)\b/u', $q)
            || preg_match('/\b(vera identità|vera identita|identità nascosta|chi è (davvero|realmente|veramente)|chi e (davvero|realmente|veramente)|prende la sua identità|prende l\'identità|assume l\'identità|si finge|si nasconde dietro|true identity|hidden identity|who really is|assumes the identity)\b/u', $q)
            || preg_match('/\b(non è effettivamente|non e effettivamente|non è davvero|non è realmente)\b/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::IDENTITY_REVEAL, TaskContract::SHAPE_LOCATION,
                entityType: 'person', count: null, coverage: 'global', relationship: null, ranking: false,
                supported: false, notice: 'LIMITED_TRICKY_INFERENCE_SUPPORT', anchors: $anchors);
        }

        // ── Tricky implicit inference ───────────────────────────────
        if (preg_match('/\b(implicitamente|sottinteso|tra le righe|lascia intendere|si può dedurre|si puo dedurre|senza dirlo|between the lines|implied|indirectly)\b/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::TRICKY_INFERENCE, TaskContract::SHAPE_EXPLANATION,
                entityType: null, count: null, coverage: 'global', relationship: null, ranking: false,
                supported: false, notice: 'LIMITED_TRICKY_INFERENCE_SUPPORT', anchors: $anchors);
        }

        // ── Quote location ──────────────────────────────────────────
        // A quoted phrase alone is not a location task (users quote
        // terms too): require a locating marker, or a quote of at least
        // three words.
        if (preg_match('/\b(dove si trova|dove appare|dove viene|in quale capitolo|in che punto|chi pronuncia|where does the quote|where is the phrase)\b/u', $q)
            || preg_match('/["«“](\S+\s+\S+\s+\S+[^"»”]*)["»”]/u', $question)) {
            return $this->contract($subquestionKey, $question, TaskContract::QUOTE_LOCATION, TaskContract::SHAPE_LOCATION,
                entityType: null, count: null, coverage: 'local', relationship: null, ranking: false,
                supported: true, notice: null, anchors: $anchors);
        }

        // ── Relationship lookup (incl. "chi sono i vicini di X") ────
        foreach (self::RELATION_LEXICON as $relation => $terms) {
            foreach ($terms as $term) {
                if (self::matchesTerm($q, $term)) {
                    $isList = preg_match('/\b(chi sono|quali sono|elenca|list)\b/u', $q) === 1;
                    $isBinary = $this->looksBinary($q);

                    return $this->contract($subquestionKey, $question,
                        $isBinary ? TaskContract::YES_NO_FACT : TaskContract::RELATIONSHIP_LOOKUP,
                        $isBinary ? TaskContract::SHAPE_YES_NO : ($isList ? TaskContract::SHAPE_LIST : TaskContract::SHAPE_SCALAR),
                        entityType: 'person', count: null, coverage: 'local', relationship: $relation,
                        ranking: false, supported: true, notice: null, anchors: $anchors);
                }
            }
        }

        // ── Plain entity list (local) ───────────────────────────────
        if (preg_match('/\b(chi sono|quali sono|elenca|elencami|list the)\b/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::LIST_ENTITIES, TaskContract::SHAPE_LIST,
                entityType: str_contains($q, 'personagg') || str_contains($q, 'character') ? 'person' : null,
                count: $this->requestedCount($q), coverage: 'local', relationship: null, ranking: false,
                supported: true, notice: null, anchors: $anchors);
        }

        // ── Comparison ──────────────────────────────────────────────
        if (preg_match('/\b(confront|compar|differenz|somiglianz|rispetto a|versus| vs )\w*/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::COMPARISON, TaskContract::SHAPE_COMPARISON,
                entityType: null, count: null, coverage: 'local', relationship: null, ranking: false,
                supported: true, notice: null, anchors: $anchors);
        }

        // ── Local description ───────────────────────────────────────
        if (preg_match('/\b(descriv|come è fatt|com\'è fatt|che aspetto|describe|what does .* look like)\w*/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::LOCAL_DESCRIPTION, TaskContract::SHAPE_DESCRIPTION,
                entityType: null, count: null, coverage: 'local', relationship: null, ranking: false,
                supported: true, notice: null, anchors: $anchors);
        }

        // ── Local explanation / mechanism ───────────────────────────
        if (preg_match('/\b(come funziona|come funzionano|perché|perche|come mai|spiega|in che senso|why|how (does|do)|explain)\b/u', $q)) {
            return $this->contract($subquestionKey, $question, TaskContract::LOCAL_EXPLANATION, TaskContract::SHAPE_EXPLANATION,
                entityType: null, count: null, coverage: 'local', relationship: null, ranking: false,
                supported: true, notice: null, anchors: $anchors);
        }

        // ── Binary state fact ("X ha una moglie in vita?") ──────────
        if ($this->looksBinary($q)) {
            return $this->contract($subquestionKey, $question, TaskContract::YES_NO_FACT, TaskContract::SHAPE_YES_NO,
                entityType: null, count: null, coverage: 'local', relationship: null, ranking: false,
                supported: true, notice: null, anchors: $anchors);
        }

        // ── Default: local fact lookup ──────────────────────────────
        $scalar = preg_match('/\b(come si chiama|qual è il nome|chi è|chi era|quale|quanti|quante|what is the name|who is)\b/u', $q) === 1;

        return $this->contract($subquestionKey, $question, TaskContract::FACT_LOOKUP,
            $scalar ? TaskContract::SHAPE_SCALAR : TaskContract::SHAPE_EXPLANATION,
            entityType: null, count: null, coverage: 'local', relationship: null, ranking: false,
            supported: true, notice: null, anchors: $anchors);
    }

    /**
     * Word-boundary-aware lexicon matching: short complete words need
     * BOTH boundaries ("son" must not match "sono"); longer entries are
     * stems and match by word-start prefix ("vicin" → "vicini").
     */
    public static function matchesTerm(string $text, string $term): bool
    {
        $pattern = mb_strlen($term) < 5
            ? '/\b'.preg_quote($term, '/').'\b/u'
            : '/\b'.preg_quote($term, '/').'/u';

        return preg_match($pattern, $text) === 1;
    }

    /**
     * Yes/no-shaped questions: start with a finite verb / "X ha|è ...?"
     * or offer a binary alternative ("buona o cattiva").
     */
    private function looksBinary(string $q): bool
    {
        return preg_match('/\b(ha|hanno|è|era|sono|erano|esiste|c\'è|si trova a?|rimane|does|is|are|was|has|have)\b.*\?/u', $q) === 1
            && preg_match('/\b(come|perché|perche|cosa|chi|dove|quando|quale|quali|what|who|where|when|which|how)\b/u', mb_substr($q, 0, 12)) !== 1
            || preg_match('/\b\p{L}+ o \p{L}+\b.*\?/u', $q) === 1;
    }

    private function requestedCount(string $q): ?int
    {
        if (preg_match('/\b(\d{1,3})\b/', $q, $matches)) {
            return (int) $matches[1];
        }

        foreach (self::ITALIAN_NUMBERS as $word => $value) {
            if (preg_match('/\b'.$word.'\b/u', $q)) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<string> content stems (>=4 chars, no stopwords) */
    private function anchorTerms(string $q): array
    {
        $stopwords = ['come', 'cosa', 'chi', 'dove', 'quando', 'perché', 'perche', 'quale', 'quali', 'sono', 'era', 'erano', 'della', 'delle', 'degli', 'dello', 'del', 'nel', 'nella', 'con', 'per', 'una', 'uno', 'gli', 'this', 'that', 'what', 'who', 'where', 'when', 'which', 'does', 'have', 'about', 'sempre', 'libro', 'testo', 'romanzo', 'book', 'fammi', 'dammi', 'elenca', 'descrivi', 'spiega', 'dimmi'];
        $words = preg_split('/[^\p{L}\']+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $anchors = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 4 && ! in_array($word, $stopwords, true)) {
                $anchors[] = $word;
            }
        }

        return array_values(array_unique($anchors));
    }

    private function contract(
        string $key, string $question, string $type, string $shape,
        ?string $entityType, ?int $count, string $coverage, ?string $relationship,
        bool $ranking, bool $supported, ?string $notice, array $anchors,
    ): TaskContract {
        return new TaskContract(
            subquestionKey: $key,
            question: $question,
            taskType: $type,
            answerShape: $shape,
            targetEntityType: $entityType,
            requestedCount: $count,
            coverageRequirement: $coverage,
            relationshipType: $relationship,
            requiresRanking: $ranking,
            supportedInM3: $supported,
            capabilityNotice: $notice,
            anchorTerms: $anchors,
        );
    }
}
