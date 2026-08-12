<?php

namespace Tests\Feature\Answers;

use App\Enums\QueryIntent;
use App\Services\Answers\QueryIntentClassifier;
use App\Services\Answers\RetrievalPolicyResolver;
use Tests\TestCase;

class QueryIntentClassifierTest extends TestCase
{
    private QueryIntentClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new QueryIntentClassifier;
    }

    public function test_classifies_canonical_intents_deterministically(): void
    {
        $cases = [
            ['Dove si trova la frase «cantami o diva» nel poema?', 1, QueryIntent::QuoteLocation],
            ['Chi pronuncia la frase "il mare colore del vino"?', 1, QueryIntent::QuoteLocation],
            ['Confronta il ruolo del mare nei due libri', 2, QueryIntent::ComparativeMultiBook],
            ['Quali differenze ci sono tra i protagonisti?', 3, QueryIntent::ComparativeMultiBook],
            ['Riassumi la trama del libro', 1, QueryIntent::GlobalSummary],
            ['Di cosa parla questo romanzo?', 1, QueryIntent::GlobalSummary],
            ['Come cambia Scout nel corso del romanzo?', 1, QueryIntent::Longitudinal],
            ['Cosa lascia intendere il narratore senza dirlo apertamente?', 1, QueryIntent::TrickyInference],
            ['Perché Penelope non riconosce subito Odisseo?', 1, QueryIntent::LocalExplanation],
            ['In che senso la fattoria è una allegoria?', 1, QueryIntent::LocalExplanation],
            ['Chi è il custode del faro?', 1, QueryIntent::PointLookup],
            ['Nome del fratello di Marta', 1, QueryIntent::PointLookup],
        ];

        foreach ($cases as [$question, $scopeSize, $expected]) {
            $this->assertSame(
                $expected,
                $this->classifier->classify($question, $scopeSize),
                'question: '.$question,
            );
        }
    }

    public function test_comparative_requires_multi_book_scope(): void
    {
        // Comparative phrasing over ONE book is not comparative-multi-book.
        $this->assertNotSame(
            QueryIntent::ComparativeMultiBook,
            $this->classifier->classify('Confronta i personaggi principali', 1),
        );
    }

    public function test_quote_extraction_prefers_longest_quoted_phrase(): void
    {
        $this->assertSame(
            'la lanterna era accesa',
            $this->classifier->extractQuotedPhrase('Dove appare "la lanterna era accesa" nel testo?'),
        );
        $this->assertSame(
            'cantami o diva del pelide Achille',
            $this->classifier->extractQuotedPhrase('Cerca «cantami o diva del pelide Achille» oppure "ira"?'),
        );
        $this->assertNull($this->classifier->extractQuotedPhrase('Nessuna citazione qui'));
        // Single quoted word: not enough to be a quote phrase.
        $this->assertNull($this->classifier->extractQuotedPhrase('Cosa significa "telemachia"?'));
    }

    public function test_m3_capability_boundary(): void
    {
        foreach ([QueryIntent::PointLookup, QueryIntent::LocalExplanation, QueryIntent::QuoteLocation, QueryIntent::ComparativeMultiBook] as $intent) {
            $this->assertTrue($intent->isSupportedInM3());
            $this->assertNull($intent->capabilityNotice());
        }

        foreach ([QueryIntent::GlobalSummary, QueryIntent::Longitudinal, QueryIntent::TrickyInference] as $intent) {
            $this->assertFalse($intent->isSupportedInM3());
            $this->assertNotNull($intent->capabilityNotice());
        }
    }

    public function test_policies_keep_reranker_off_and_bound_budgets(): void
    {
        $resolver = new RetrievalPolicyResolver;

        foreach (QueryIntent::cases() as $intent) {
            $policy = $resolver->resolve($intent, 2);
            $this->assertFalse($policy->rerank, $intent->value.' must not enable reranking by default');
            $this->assertGreaterThan(0, $policy->topK);
            $this->assertGreaterThanOrEqual($policy->topK, $policy->expansionTopK);
        }

        $comparative = $resolver->resolve(QueryIntent::ComparativeMultiBook, 3);
        $this->assertTrue($comparative->perBook);
        $this->assertGreaterThanOrEqual(4, $comparative->perBookTopK);

        $quote = $resolver->resolve(QueryIntent::QuoteLocation, 1);
        $this->assertTrue($quote->exactFirst);
    }
}
