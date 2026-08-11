<?php

namespace App\Services\Retrieval\Retrievers;

use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL lexical retrieval over the generated weighted tsvector
 * ('simple' configuration: language-agnostic, multilingual-safe —
 * heading terms weight A, body terms weight B). Queries are built with
 * websearch_to_tsquery (parameterized, no raw tsquery concatenation).
 * PostgreSQL-only by design; proven in the integration suite.
 */
class LexicalRetriever
{
    /**
     * @param  list<int>  $assetIds
     * @return list<array{chunk: RetrievalChunk, rank: int, score: float}>
     */
    public function search(RetrievalGeneration $generation, array $assetIds, string $query, int $limit): array
    {
        if ($query === '' || $assetIds === []) {
            return [];
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return []; // lexical retrieval requires PostgreSQL FTS
        }

        // ts_rank_cd normalization 32: rank/(rank+1) → results in (0,1),
        // still NOT a calibrated probability (documented).
        $rows = DB::select(
            'SELECT id, ts_rank_cd(tsv, websearch_to_tsquery(\'simple\', ?), 32) AS score
             FROM retrieval_chunks
             WHERE retrieval_generation_id = ?
               AND book_asset_id IN ('.implode(',', array_fill(0, count($assetIds), '?')).')
               AND tsv @@ websearch_to_tsquery(\'simple\', ?)
             ORDER BY score DESC, book_asset_id ASC, ordinal ASC
             LIMIT ?',
            array_merge([$query, $generation->id], $assetIds, [$query, $limit]),
        );

        if ($rows === []) {
            return [];
        }

        $chunks = RetrievalChunk::query()
            ->whereIn('id', array_column($rows, 'id'))
            ->with('spans')
            ->get()
            ->keyBy('id');

        $results = [];
        $rank = 1;

        foreach ($rows as $row) {
            $chunk = $chunks->get($row->id);

            if ($chunk !== null) {
                $results[] = ['chunk' => $chunk, 'rank' => $rank++, 'score' => (float) $row->score];
            }
        }

        return $results;
    }
}
