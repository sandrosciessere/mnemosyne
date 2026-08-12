<?php

namespace Tests\Feature\Retrieval;

use App\Models\RetrievalChunk;
use App\Services\Retrieval\RankFusion;
use App\Services\Retrieval\Retrievers\DenseRetriever;
use Tests\TestCase;

/**
 * F1 regression: pgvector's relaxed_order iterative scans may return
 * overfetched rows out of global distance order. Dense ranks feed RRF,
 * so rows must be explicitly distance-sorted (deterministic id
 * tie-break) before ranks are assigned.
 */
class DenseOrderingTest extends TestCase
{
    private function row(int $chunkId, float $distance): object
    {
        return (object) ['retrieval_chunk_id' => $chunkId, 'distance' => $distance];
    }

    public function test_rows_are_resorted_by_distance_with_deterministic_tie_break(): void
    {
        // Deliberately non-distance order, as relaxed_order can produce.
        $rows = [
            $this->row(5, 0.42),
            $this->row(9, 0.17),
            $this->row(2, 0.42),
            $this->row(7, 0.03),
            $this->row(4, 0.17),
        ];

        $sorted = DenseRetriever::sortByDistance($rows);

        $this->assertSame([7, 4, 9, 2, 5], array_column($sorted, 'retrieval_chunk_id'));
        $this->assertSame([0.03, 0.17, 0.17, 0.42, 0.42], array_column($sorted, 'distance'));

        // Ties broken by chunk id (4 before 9, 2 before 5) — repeatable.
        $this->assertSame($sorted, DenseRetriever::sortByDistance(array_reverse($rows)));
    }

    public function test_string_numerics_from_the_driver_sort_numerically(): void
    {
        $rows = [
            $this->row(1, '0.9'),
            $this->row(2, '0.10'),
        ];

        $sorted = DenseRetriever::sortByDistance($rows);

        $this->assertSame([2, 1], array_column($sorted, 'retrieval_chunk_id'));
    }

    public function test_rrf_consumes_corrected_dense_ranks(): void
    {
        // If ranks came from the raw (unsorted) row order, chunk 5 would
        // be dense rank 1; after sorting, chunk 7 is. Feed both orders
        // through the real fusion config and verify the sorted ranking
        // wins deterministically.
        $mk = function (int $id) {
            $chunk = new RetrievalChunk;
            $chunk->id = $id;
            $chunk->forceFill(['ordinal' => $id, 'canonical_start' => $id * 100, 'canonical_end' => $id * 100 + 50]);

            return $chunk;
        };

        $sortedRows = DenseRetriever::sortByDistance([
            $this->row(5, 0.42), $this->row(7, 0.03),
        ]);

        $dense = [];
        $rank = 1;
        foreach ($sortedRows as $row) {
            $dense[] = ['chunk' => $mk($row->retrieval_chunk_id), 'rank' => $rank++, 'distance' => (float) $row->distance];
        }

        $fused = (new RankFusion)->fuse(
            ['dense' => $dense],
            ['k' => 60, 'weights' => ['dense' => 1.0]],
        );

        $this->assertSame(7, $fused[0]['chunk']->id, 'nearest chunk must carry dense rank 1 into RRF');
        $this->assertSame(1, $fused[0]['components']['dense']['rank']);
    }
}
