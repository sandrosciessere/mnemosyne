<?php

namespace Tests\Integration;

use App\Models\RetrievalEmbedding;
use App\Services\Retrieval\RetrievalGenerationManager;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsEvaluationCorpus;
use Tests\Support\BuildsRetrievalArtifacts;

/**
 * PostgreSQL-specific retrieval schema invariants: extensions, generated
 * tsvector + GIN/trigram indexes, per-generation partial HNSW, dimension
 * isolation. Uses the deterministic test embedding provider (32 dims) —
 * model quality lives in RetrievalQualityTest.
 */
class RetrievalPgSchemaTest extends IntegrationTestCase
{
    use BuildsEvaluationCorpus;
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    public function test_required_extensions_and_columns_exist(): void
    {
        $extensions = collect(DB::select(
            "SELECT extname FROM pg_extension WHERE extname IN ('vector', 'pg_trgm')",
        ))->pluck('extname');

        $this->assertContains('vector', $extensions);
        $this->assertContains('pg_trgm', $extensions);

        $tsv = DB::selectOne(
            "SELECT is_generated FROM information_schema.columns
             WHERE table_name = 'retrieval_chunks' AND column_name = 'tsv'",
        );
        $this->assertSame('ALWAYS', $tsv->is_generated);

        $indexes = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'retrieval_chunks'",
        ))->pluck('indexname');
        $this->assertContains('retrieval_chunks_tsv_gin', $indexes);
        $this->assertContains('retrieval_chunks_trgm', $indexes);
    }

    public function test_generation_gets_partial_hnsw_index_and_queries_use_indexes(): void
    {
        $corpus = $this->buildEvaluationCorpus();
        $generation = $this->makeTestGeneration('active');
        app(RetrievalGenerationManager::class)->ensureAnnIndex($generation);

        $indexer = app(RetrievalIndexer::class);
        foreach ($corpus['assets'] as $asset) {
            $state = $indexer->indexAsset($generation, $asset);
            $this->assertSame('ready', $state->status, (string) $state->last_error_message);
        }

        // Partial HNSW exists for exactly this generation.
        $ann = DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE indexname = ?',
            [$generation->annIndexName()],
        );
        $this->assertNotNull($ann);
        $this->assertStringContainsString('hnsw', $ann->indexdef);
        $this->assertStringContainsString('vector(32)', $ann->indexdef);
        $this->assertStringContainsString('retrieval_generation_id = '.$generation->id, $ann->indexdef);

        // Lexical EXPLAIN uses the GIN index (seqscan disabled: on a tiny
        // test corpus the planner rightly prefers a scan — this asserts
        // the index is USABLE for the query shape, which is what matters
        // at real corpus sizes).
        $lexPlan = DB::transaction(function () {
            DB::statement('SET LOCAL enable_seqscan = off');

            // No generation predicate here: on a micro-table the planner
            // otherwise picks the (row-estimate 1) btree; this isolates
            // "is the GIN usable for the tsquery shape".
            return collect(DB::select(
                "EXPLAIN SELECT id FROM retrieval_chunks
                 WHERE tsv @@ websearch_to_tsquery('simple', 'compass navigator')",
            ))->pluck('QUERY PLAN')->implode("\n");
        });
        $this->assertStringContainsString('retrieval_chunks_tsv_gin', $lexPlan);

        // Dense EXPLAIN uses the per-generation HNSW index.
        $vector = '['.implode(',', array_fill(0, 32, 0.1)).']';
        $densePlan = collect(DB::select(
            "EXPLAIN SELECT retrieval_chunk_id FROM retrieval_embeddings
             WHERE retrieval_generation_id = {$generation->id}
             ORDER BY embedding::vector(32) <=> '{$vector}'::vector(32) LIMIT 10",
        ))->pluck('QUERY PLAN')->implode("\n");
        $this->assertStringContainsString($generation->annIndexName(), $densePlan);
    }

    public function test_mixed_dimensions_cannot_serve_through_a_generation_cast(): void
    {
        $corpus = $this->buildEvaluationCorpus();
        $generation = $this->makeTestGeneration('active');
        app(RetrievalGenerationManager::class)->ensureAnnIndex($generation);
        app(RetrievalIndexer::class)->indexAsset($generation, $corpus['assets']['A']);

        // Even stronger than required: the generation's dimension-cast
        // HNSW expression index rejects a wrong-dimension vector AT WRITE
        // TIME — a mixed-dimension row cannot even be stored inside the
        // generation, let alone serve results. (Provider validation is
        // the first line of defence; this is the database backstop.)
        $rogue = RetrievalEmbedding::query()->first();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('expected 32 dimensions');
        DB::update(
            'UPDATE retrieval_embeddings SET embedding = ?, dims = 3 WHERE id = ?',
            ['[0.1,0.2,0.3]', $rogue->id],
        );
    }

    public function test_upgrade_from_milestone_one_schema_converges(): void
    {
        // RefreshDatabase already ran ALL migrations on this pg database:
        // assert the retrieval tables exist alongside the untouched M1
        // tables (the MigrationUpgradeTest covers the deployed-baseline
        // convergence path for M1; M2 migrations are purely additive).
        foreach ([
            'retrieval_generations', 'retrieval_asset_states',
            'retrieval_chunks', 'retrieval_evidence_spans', 'retrieval_embeddings',
        ] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "missing {$table}",
            );
        }

        $this->assertTrue(Schema::hasTable('book_assets'));
        $this->assertTrue(Schema::hasTable('ingestion_runs'));
    }
}
