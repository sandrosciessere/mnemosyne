<?php

namespace Tests\Integration;

use App\Models\RetrievalGeneration;
use App\Services\Retrieval\RetrievalIndexer;
use App\Services\Retrieval\Retrievers\LexicalRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvaluationCorpus;
use Tests\Support\BuildsRetrievalArtifacts;

/**
 * E3 regression (real PostgreSQL FTS): strict websearch queries AND every
 * term, so natural-language queries with function words return nothing
 * under the 'simple' multilingual configuration. Lexical version 1.1.0
 * adds a meaningful-token OR fallback that fires ONLY when strict yields
 * zero rows; 1.0.0 generations keep strict-only behavior.
 */
class RetrievalLexicalFallbackTest extends IntegrationTestCase
{
    use BuildsEvaluationCorpus;
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    private array $corpus;

    private RetrievalGeneration $generation;

    private function indexCorpus(): void
    {
        $this->corpus = $this->buildEvaluationCorpus();
        $this->generation = $this->makeTestGeneration('active');

        $indexer = app(RetrievalIndexer::class);
        foreach ($this->corpus['assets'] as $asset) {
            $state = $indexer->indexAsset($this->generation, $asset);
            $this->assertSame('ready', $state->status, (string) $state->last_error_message);
        }
    }

    private function allAssetIds(): array
    {
        return array_map(fn ($asset) => $asset->id, array_values($this->corpus['assets']));
    }

    public function test_italian_natural_language_query_falls_back_and_finds_evidence(): void
    {
        $this->indexCorpus();

        // "orologi" (no stemming in 'simple') and the function words make
        // the strict AND query impossible — pre-fix this returned [].
        $query = 'chi riparava nel vicolo i meccanismi antichi degli orologi fermi';

        $outcome = app(LexicalRetriever::class)->search(
            $this->generation, $this->allAssetIds(), $query, 40,
        );

        $this->assertSame('or_fallback', $outcome['strategy']);
        $this->assertNotEmpty($outcome['candidates']);

        $top = collect($outcome['candidates'])->take(5);
        $this->assertTrue(
            $top->contains(fn ($candidate) => str_contains($candidate['chunk']->source_text, 'riparava meccanismi antichi')),
            'the orologiaio chunk must rank in the fallback top 5',
        );
    }

    public function test_english_natural_language_query_falls_back_and_finds_evidence(): void
    {
        $this->indexCorpus();

        $query = 'who sketched the charts from the memory of shorelines without any atlas';

        $outcome = app(LexicalRetriever::class)->search(
            $this->generation, $this->allAssetIds(), $query, 40,
        );

        $this->assertSame('or_fallback', $outcome['strategy']);
        $this->assertTrue(
            collect($outcome['candidates'])->take(5)
                ->contains(fn ($candidate) => str_contains($candidate['chunk']->source_text, 'memory of shorelines')),
            'the coastline-memory chunk must rank in the fallback top 5',
        );
    }

    public function test_accented_natural_language_query_falls_back(): void
    {
        $this->indexCorpus();

        // "già"/"perduta" (vs source "perdute") break the strict query;
        // accented tokens must survive fallback tokenization.
        $query = 'già perduta ogni oscillazione del pendolo di ottone';

        $outcome = app(LexicalRetriever::class)->search(
            $this->generation, $this->allAssetIds(), $query, 40,
        );

        $this->assertSame('or_fallback', $outcome['strategy']);
        $this->assertTrue(
            collect($outcome['candidates'])->take(5)
                ->contains(fn ($candidate) => str_contains($candidate['chunk']->source_text, 'pendolo di ottone')),
        );
    }

    public function test_keyword_queries_keep_strict_strategy_and_ranking(): void
    {
        $this->indexCorpus();

        $outcome = app(LexicalRetriever::class)->search(
            $this->generation, $this->allAssetIds(), 'orologiaio meccanismi antichi bottega', 40,
        );

        $this->assertSame('strict', $outcome['strategy']);
        $this->assertNotEmpty($outcome['candidates']);
        $this->assertStringContainsString(
            'riparava meccanismi antichi',
            $outcome['candidates'][0]['chunk']->source_text,
        );
    }

    public function test_fallback_ranks_multi_term_evidence_above_single_term_decoys(): void
    {
        $this->indexCorpus();

        // Decoy (previsioni del tempo sul giardino) shares "tempo"; the
        // orologiaio chunk matches ingranaggi+antichi+bottega.
        $query = 'lo scorrere del tempo tra gli ingranaggi antichi della bottega';

        $outcome = app(LexicalRetriever::class)->search(
            $this->generation, $this->allAssetIds(), $query, 40,
        );

        $this->assertSame('or_fallback', $outcome['strategy']);

        $rankOf = function (string $needle) use ($outcome): ?int {
            foreach ($outcome['candidates'] as $candidate) {
                if (str_contains($candidate['chunk']->source_text, $needle)) {
                    return $candidate['rank'];
                }
            }

            return null;
        };

        $target = $rankOf('riparava meccanismi antichi');
        $decoy = $rankOf('previsioni del tempo');

        $this->assertNotNull($target);
        if ($decoy !== null) {
            $this->assertLessThan($decoy, $target, 'multi-term evidence must outrank the single-term decoy');
        }
    }

    public function test_lexical_version_1_0_generations_keep_strict_only_behavior(): void
    {
        $this->indexCorpus();

        // Same generation data, but a 1.0.0 lexical snapshot: no fallback.
        $config = $this->generation->config;
        $config['lexical']['version'] = '1.0.0';
        $this->generation->forceFill(['config' => $config])->save();

        $outcome = app(LexicalRetriever::class)->search(
            $this->generation->refresh(), $this->allAssetIds(),
            'chi riparava nel vicolo i meccanismi antichi degli orologi fermi', 40,
        );

        $this->assertSame('strict', $outcome['strategy']);
        $this->assertSame([], $outcome['candidates']);
    }
}
