<?php

namespace Tests\Feature\Retrieval;

use App\Models\RetrievalChunk;
use App\Models\RetrievalEmbedding;
use App\Models\RetrievalEvidenceSpan;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

class RetrievalIndexerTest extends TestCase
{
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();
    }

    private function docs(): array
    {
        return [
            0 => array_merge(
                [['type' => 'heading', 'text' => 'Chapter One', 'heading_path' => ['Chapter One']]],
                array_map(fn ($index) => [
                    'text' => "Paragraph {$index} narrates the deterministic library events in satisfying detail for indexing.",
                ], range(1, 8)),
            ),
        ];
    }

    public function test_indexing_produces_chunks_spans_and_embeddings(): void
    {
        $built = $this->buildArtifacts($this->docs());
        $generation = $this->makeTestGeneration();

        $state = app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);

        $this->assertSame('ready', $state->status);
        $this->assertGreaterThan(1, $state->chunk_count);
        $this->assertSame($state->chunk_count, $state->embedded_count);

        $this->assertSame($state->chunk_count, RetrievalChunk::query()->count());
        $this->assertSame($state->chunk_count, RetrievalEmbedding::query()->count());
        $this->assertGreaterThan($state->chunk_count, RetrievalEvidenceSpan::query()->count());

        $embedding = RetrievalEmbedding::query()->first();
        $this->assertSame('deterministic-test', $embedding->model_key);
        $this->assertSame(32, (int) $embedding->dims);
        $this->assertNotEmpty($embedding->embedding_text_sha256);
    }

    public function test_reindexing_a_ready_asset_is_an_idempotent_noop(): void
    {
        $built = $this->buildArtifacts($this->docs());
        $generation = $this->makeTestGeneration();
        $indexer = app(RetrievalIndexer::class);

        $indexer->indexAsset($generation, $built['asset']);
        $chunkIds = RetrievalChunk::query()->pluck('id')->all();

        $state = $indexer->indexAsset($generation, $built['asset']);

        $this->assertSame('ready', $state->status);
        // Same rows — nothing duplicated, nothing rebuilt.
        $this->assertSame($chunkIds, RetrievalChunk::query()->pluck('id')->all());
        $this->assertSame(count($chunkIds), RetrievalEmbedding::query()->count());
    }

    public function test_crash_between_chunking_and_embedding_resumes_safely(): void
    {
        $built = $this->buildArtifacts($this->docs());
        $generation = $this->makeTestGeneration();
        $indexer = app(RetrievalIndexer::class);

        $indexer->indexAsset($generation, $built['asset']);

        // Simulate a crash that lost part of the embedding phase.
        $state = $generation->assetStates()->first();
        RetrievalEmbedding::query()->limit(2)->delete();
        $state->forceFill(['status' => 'embedding', 'embedded_count' => 0])->save();

        $resumed = $indexer->indexAsset($generation, $built['asset']);

        $this->assertSame('ready', $resumed->status);
        $this->assertSame(
            RetrievalChunk::query()->count(),
            RetrievalEmbedding::query()->count(),
        );
        // Exactly one embedding per chunk (unique constraint intact).
        $this->assertSame(
            RetrievalEmbedding::query()->distinct('retrieval_chunk_id')->count(),
            RetrievalEmbedding::query()->count(),
        );
    }

    public function test_source_hash_mismatch_fails_permanently(): void
    {
        $built = $this->buildArtifacts($this->docs());
        $generation = $this->makeTestGeneration();

        // Corrupt the canonical artifact after state creation.
        Storage::disk('data')->put(
            $built['asset']->artifactDir($built['asset']->pipeline_version).'/canonical.txt',
            'tampered content',
        );

        $state = app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);

        $this->assertSame('failed', $state->status);
        $this->assertSame('SOURCE_HASH_MISMATCH', $state->last_error_code);
        $this->assertSame(0, RetrievalChunk::query()->count(), 'no chunks may exist for a mismatched source');
    }

    public function test_retrieval_failure_never_touches_ingestion_status(): void
    {
        $built = $this->buildArtifacts($this->docs());
        Storage::disk('data')->delete(
            $built['asset']->artifactDir($built['asset']->pipeline_version).'/canonical.txt',
        );
        $generation = $this->makeTestGeneration();

        $ingestionStatus = $built['asset']->ingestion_status;
        $state = app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);

        $this->assertSame('failed', $state->status);
        $this->assertSame($ingestionStatus, $built['asset']->refresh()->ingestion_status);
    }
}
