<?php

namespace Tests\Integration;

use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use App\Services\Ingestion\WorkerClient;
use App\Services\Retrieval\EvaluationRunner;
use App\Services\Retrieval\HybridSearchService;
use App\Services\Retrieval\RetrievalGenerationManager;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvaluationCorpus;
use Tests\Support\BuildsRetrievalArtifacts;

/**
 * The Milestone 2 quality gates against REAL components: PostgreSQL +
 * pgvector, the real worker, the real multilingual-e5-small embeddings
 * and the real mmarco cross-encoder reranker. Nothing mocked.
 */
class RetrievalQualityTest extends IntegrationTestCase
{
    use BuildsEvaluationCorpus;
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    private RetrievalGeneration $generation;

    private array $corpus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRealWorker();

        // Real models must be provisioned in the worker cache.
        try {
            $models = collect(app(WorkerClient::class)
                ->getJson('/internal/v1/retrieval/models')['models'] ?? []);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('worker model registry unreachable: '.$exception->getMessage());
        }

        $embedding = $models->firstWhere('model_key', config('mnemosyne.retrieval.embedding.model_key'));
        $reranker = $models->firstWhere('model_key', config('mnemosyne.retrieval.reranker.model_key'));

        if (! ($embedding['cached'] ?? false) || ! ($reranker['cached'] ?? false)) {
            if (getenv('RUN_INTEGRATION') === '1') {
                $this->fail('retrieval models are not provisioned in the worker cache');
            }
            $this->markTestSkipped('retrieval models not provisioned');
        }
    }

    private function indexCorpus(): void
    {
        $this->corpus = $this->buildEvaluationCorpus();
        $this->generation = app(RetrievalGenerationManager::class)->create();

        $indexer = app(RetrievalIndexer::class);
        foreach ($this->corpus['assets'] as $asset) {
            $state = $indexer->indexAsset($this->generation, $asset);
            $this->assertSame('ready', $state->status, (string) $state->last_error_message);
        }

        app(RetrievalGenerationManager::class)->activate($this->generation);
    }

    private function evaluate(array $modes): array
    {
        $cases = json_decode(
            (string) file_get_contents(base_path('tests/retrieval/evaluation-cases.json')),
            true,
        );

        return app(EvaluationRunner::class)->run(
            $this->generation, $cases, $this->corpus['assets'], $modes, 10,
        );
    }

    public function test_full_quality_gates_across_all_modes(): void
    {
        $this->indexCorpus();

        $metrics = $this->evaluate(['exact', 'lexical', 'dense', 'hybrid', 'hybrid+rerank']);
        fwrite(STDERR, "\nretrieval benchmark: ".json_encode($metrics['modes'], JSON_PRETTY_PRINT)."\n");

        $perCase = $metrics['per_case'];

        // ---- Exact gate: every literal phrase found (incl. Unicode,
        // emoji and the chunk-boundary case), zero false positives.
        foreach (['A-exact-compass', 'B-exact-pendolo', 'C-exact-unicode', 'C-exact-emoji', 'C-exact-boundary'] as $caseId) {
            $this->assertNotNull($perCase['exact'][$caseId]['rank'], "exact miss: {$caseId}");
        }
        $this->assertFalse($perCase['exact']['C-exact-absent']['false_positive'], 'exact search fabricated a match');

        // ---- Lexical gate: keyword queries rank the relevant chunk.
        $this->assertNotNull($perCase['lexical']['A-lexical-instruments']['rank']);
        $this->assertLessThanOrEqual(5, $perCase['lexical']['A-lexical-instruments']['rank']);
        $this->assertNotNull($perCase['lexical']['B-lexical-orologiaio']['rank']);
        $this->assertLessThanOrEqual(5, $perCase['lexical']['B-lexical-orologiaio']['rank']);

        // ---- Dense gate: paraphrases whose wording differs from the
        // source are found semantically (in both languages).
        $this->assertNotNull($perCase['dense']['A-semantic-coastlines']['rank'], 'EN paraphrase not found by dense retrieval');
        $this->assertLessThanOrEqual(5, $perCase['dense']['A-semantic-coastlines']['rank']);
        $this->assertNotNull($perCase['dense']['B-semantic-artigiano']['rank'], 'IT paraphrase not found by dense retrieval');
        $this->assertLessThanOrEqual(5, $perCase['dense']['B-semantic-artigiano']['rank']);

        // ---- Hybrid gate: fusion is at least as good as each component.
        $this->assertGreaterThanOrEqual(
            max($metrics['modes']['lexical']['recall_at_k'], $metrics['modes']['dense']['recall_at_k']),
            $metrics['modes']['hybrid']['recall_at_k'] + 1e-9,
        );
        $this->assertGreaterThanOrEqual(0.9, $metrics['modes']['hybrid']['recall_at_k']);

        // ---- Reranker gate: preserves or improves ranking quality.
        $this->assertGreaterThanOrEqual(
            $metrics['modes']['hybrid']['mrr'] - 0.1,
            $metrics['modes']['hybrid+rerank']['mrr'],
            'reranker materially degraded ranking on the benchmark',
        );

        // Adversarial: with reranking, the true navigation passage beats
        // the cooking decoy sharing its vocabulary.
        $this->assertNotNull($perCase['hybrid+rerank']['A-adversarial-compass']['rank']);
        $this->assertLessThanOrEqual(3, $perCase['hybrid+rerank']['A-adversarial-compass']['rank']);
    }

    public function test_provenance_round_trip_and_exact_offsets_on_real_stack(): void
    {
        $this->indexCorpus();

        $service = app(HybridSearchService::class);
        $assetIds = array_map(fn ($asset) => $asset->id, array_values($this->corpus['assets']));

        $outcome = $service->search(
            $this->generation, $assetIds,
            'il pendolo di ottone segnava le ore perdute',
            'exact', 5, false,
        );

        $this->assertNotEmpty($outcome['results']);
        $hit = $outcome['results'][0];
        $match = $hit['components']['exact']['matches'][0];

        // retrieved result → EvidenceSpan → canonical offsets → source text.
        $canonical = $this->corpus['canonicals']['B'];
        $this->assertSame(
            'il pendolo di ottone segnava le ore perdute',
            mb_substr($canonical, $match['canonical_start'], $match['canonical_end'] - $match['canonical_start']),
        );

        $chunk = $hit['chunk'];
        foreach ($chunk->spans as $span) {
            $fromCanonical = mb_substr($canonical, $span->canonical_start, $span->canonical_end - $span->canonical_start);
            $fromChunk = mb_substr($chunk->source_text, $span->chunk_start, $span->chunk_end - $span->chunk_start);
            $this->assertSame($fromCanonical, $fromChunk);
            $this->assertSame($fromCanonical, $this->utf16Slice($canonical, $span->utf16_start, $span->utf16_end));
        }

        // Stage timings exist for the demonstrable query path.
        $hybrid = $service->search(
            $this->generation, $assetIds,
            'un artigiano che aggiusta vecchi orologi', 'hybrid', 5, true,
        );
        fwrite(STDERR, "\nhybrid+rerank timings_ms: ".json_encode($hybrid['timings_ms'])."\n");
        foreach (['scope', 'exact', 'lexical', 'dense', 'fusion', 'rerank', 'selection', 'total'] as $stage) {
            $this->assertArrayHasKey($stage, $hybrid['timings_ms']);
        }
        $this->assertTrue($hybrid['diagnostics']['reranker_used']);
    }

    public function test_generation_isolation_and_reindex_determinism(): void
    {
        $this->indexCorpus();

        $firstHashes = RetrievalChunk::query()
            ->where('retrieval_generation_id', $this->generation->id)
            ->orderBy('book_asset_id')->orderBy('ordinal')
            ->pluck('content_sha256')->all();

        // Second generation, same config → identical deterministic chunks,
        // fully isolated rows; generation A remains intact and active.
        $second = app(RetrievalGenerationManager::class)->create();
        $indexer = app(RetrievalIndexer::class);
        foreach ($this->corpus['assets'] as $asset) {
            $indexer->indexAsset($second, $asset);
        }

        $secondHashes = RetrievalChunk::query()
            ->where('retrieval_generation_id', $second->id)
            ->orderBy('book_asset_id')->orderBy('ordinal')
            ->pluck('content_sha256')->all();

        $this->assertSame($firstHashes, $secondHashes, 'chunking must be deterministic across generations');
        $this->assertSame('active', $this->generation->refresh()->status);
        $this->assertSame('building', $second->refresh()->status);

        // Activation flips atomically; superseded data survives.
        app(RetrievalGenerationManager::class)->activate($second);
        $this->assertSame('superseded', $this->generation->refresh()->status);
        $this->assertSame('active', $second->refresh()->status);
        $this->assertSame(
            count($firstHashes),
            RetrievalChunk::query()->where('retrieval_generation_id', $this->generation->id)->count(),
        );
    }
}
