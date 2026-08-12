<?php

namespace App\Services\Answers;

use App\Enums\QueryIntent;

/**
 * Deterministic, versioned query-intent classification (query-intent
 * 1.0.0). Rule-based by design: no model call, fully reproducible, and
 * the classified intent + version are persisted on every answer run.
 * Rules are ordered by specificity; the first match wins.
 */
class QueryIntentClassifier
{
    /**
     * 1.1.0: hidden-identity / revelation questions ("chi prende la sua
     * identità", "chi è realmente", "chi si rivela") classify as
     * TRICKY_INFERENCE — they usually need evidence beyond a bounded
     * Top-K window, so M3 answers them with an explicit capability
     * notice and only what bounded evidence supports. Never guess the
     * reveal.
     */
    public const VERSION = 'query-intent 1.1.0';

    /**
     * @param  int  $scopeSize  number of books selected for this answer
     */
    public function classify(string $question, int $scopeSize): QueryIntent
    {
        $q = mb_strtolower(trim($question));

        if ($this->extractQuotedPhrase($question) !== null || $this->matchesAny($q, [
            'dove si trova', 'dove appare', 'dove viene detto', 'in quale capitolo',
            'in quale punto', 'chi pronuncia', 'chi dice la frase',
            'where does the quote', 'where is the phrase', 'locate the quote',
        ])) {
            return QueryIntent::QuoteLocation;
        }

        if ($scopeSize >= 2 && $this->matchesAny($q, [
            'confront', 'compar', 'differenz', 'somiglianz', 'in comune',
            'rispetto a', 'entrambi i libri', 'nei due libri', ' vs ', 'versus',
            'differences', 'similarities', 'both books', 'each book',
        ])) {
            return QueryIntent::ComparativeMultiBook;
        }

        if ($this->matchesAny($q, [
            'riassum', 'riassunto', 'sintesi del libro', 'sintesi dell', 'la trama',
            'di cosa parla', 'di che cosa parla', 'summariz', 'summary of', 'plot of',
            'overview of the book',
        ])) {
            return QueryIntent::GlobalSummary;
        }

        if ($this->matchesAny($q, [
            'evolv', 'evoluzione', 'nel corso del', 'nel corso dell', "durante l'intero",
            'attraverso il libro', 'arco narrativo', "dall'inizio alla fine", 'come cambia',
            'throughout the', 'over the course', 'change over the',
        ])) {
            return QueryIntent::Longitudinal;
        }

        if ($this->matchesAny($q, [
            'implicitamente', 'sottinteso', 'sottintende', 'tra le righe', 'lascia intendere',
            'si può dedurre', 'si puo dedurre', 'cosa suggerisce indirettamente',
            'senza dirlo', 'imply', 'implied', 'between the lines', 'indirectly suggest',
            // Hidden-identity / revelation / impersonation questions.
            'vera identità', 'vera identita', 'identità nascosta', 'identita nascosta',
            'chi è realmente', 'chi e realmente', 'chi è veramente', 'chi e veramente',
            'si rivela', 'si rivelerà', 'scambio di persona', "assume l'identità",
            "assume l'identita", 'prende la sua identità', 'prende la sua identita',
            "prende l'identità", "prende l'identita", 'si nasconde dietro', 'si finge',
            'true identity', 'hidden identity', 'who really is', 'who is really',
            'assumes the identity', 'takes over the identity', 'impersonat',
        ])) {
            return QueryIntent::TrickyInference;
        }

        if ($this->matchesAny($q, [
            'perché', 'perche', 'come mai', 'spiega', 'spiegami', 'cosa significa',
            'che cosa significa', 'in che senso', 'che ruolo', 'per quale motivo',
            'why', 'explain', 'what does', 'what is the meaning', 'significato di',
        ])) {
            return QueryIntent::LocalExplanation;
        }

        return QueryIntent::PointLookup;
    }

    /**
     * The literal phrase to feed the exact retriever for quote-location
     * questions: the longest quoted substring («…», “…”, "…", '…') with
     * at least two words; null when the question contains no usable
     * quotation.
     */
    public function extractQuotedPhrase(string $question): ?string
    {
        $patterns = [
            '/«([^»]{3,400})»/u',
            '/“([^”]{3,400})”/u',
            '/"([^"]{3,400})"/u',
            '/\'([^\']{10,400})\'/u',
        ];

        $best = null;

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $question, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $candidate = trim($candidate);

                    if (str_word_count($candidate, 0, 'àèéìòùáíóúäëïöü') >= 2
                        && ($best === null || mb_strlen($candidate) > mb_strlen($best))) {
                        $best = $candidate;
                    }
                }
            }
        }

        return $best;
    }

    /** @param list<string> $needles */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
