<?php

namespace App\Services\Retrieval\Retrievers;

use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use App\Services\Retrieval\Embedding\EmbeddingProviderFactory;
use Illuminate\Support\Facades\DB;

/**
 * pgvector dense retrieval against the generation's partial HNSW index.
 * Scope filtering happens in SQL; to protect against ANN under-return
 * with restrictive filters we overfetch (configurable factor) and enable
 * pgvector 0.8 iterative scans. Cosine distance on normalized vectors;
 * similarity reported as 1 - distance. PostgreSQL-only by design.
 */
class DenseRetriever
{
    public function __construct(private readonly EmbeddingProviderFactory $factory) {}

    /**
     * @param  list<int>  $assetIds
     * @return list<array{chunk: RetrievalChunk, rank: int, distance: float, similarity: float}>
     */
    public function search(RetrievalGeneration $generation, array $assetIds, string $query, int $limit): array
    {
        if ($query === '' || $assetIds === []) {
            return [];
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $vector = $this->factory->forGeneration($generation)->embedQuery($query);
        $literal = '['.implode(',', $vector).']';
        $dims = (int) $generation->embedding_dimensions;
        $overfetch = max(1, (int) config('mnemosyne.retrieval.search.dense_overfetch'));
        $efSearch = max($limit * $overfetch, (int) $generation->config['ann']['hnsw_ef_search']);

        $rows = DB::transaction(function () use ($generation, $assetIds, $literal, $dims, $limit, $overfetch, $efSearch) {
            // Session-local ANN tuning (resets at transaction end).
            DB::statement('SET LOCAL hnsw.ef_search = '.(int) $efSearch);
            DB::statement("SET LOCAL hnsw.iterative_scan = 'relaxed_order'");

            return DB::select(
                'SELECT retrieval_chunk_id, (embedding::vector('.$dims.') <=> ?::vector('.$dims.')) AS distance
                 FROM retrieval_embeddings
                 WHERE retrieval_generation_id = ?
                   AND book_asset_id IN ('.implode(',', array_fill(0, count($assetIds), '?')).')
                 ORDER BY embedding::vector('.$dims.') <=> ?::vector('.$dims.')
                 LIMIT ?',
                array_merge([$literal, $generation->id], $assetIds, [$literal, $limit * $overfetch]),
            );
        });

        $rows = array_slice(self::sortByDistance($rows), 0, $limit);

        if ($rows === []) {
            return [];
        }

        $chunks = RetrievalChunk::query()
            ->whereIn('id', array_column($rows, 'retrieval_chunk_id'))
            ->with('spans')
            ->get()
            ->keyBy('id');

        $results = [];
        $rank = 1;

        foreach ($rows as $row) {
            $chunk = $chunks->get($row->retrieval_chunk_id);

            if ($chunk !== null) {
                $distance = (float) $row->distance;
                $results[] = [
                    'chunk' => $chunk,
                    'rank' => $rank++,
                    'distance' => $distance,
                    'similarity' => 1.0 - $distance,
                ];
            }
        }

        return $results;
    }

    /**
     * hnsw.iterative_scan = relaxed_order does NOT guarantee globally
     * distance-sorted rows: overfetched candidates are re-sorted
     * explicitly before dense ranks are assigned (they feed RRF).
     * Deterministic tie-break on chunk id.
     *
     * @param  list<object{retrieval_chunk_id: int|string, distance: float|string}>  $rows
     * @return list<object>
     */
    public static function sortByDistance(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $byDistance = (float) $a->distance <=> (float) $b->distance;

            return $byDistance !== 0 ? $byDistance : ((int) $a->retrieval_chunk_id <=> (int) $b->retrieval_chunk_id);
        });

        return $rows;
    }
}
