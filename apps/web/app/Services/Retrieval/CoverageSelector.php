<?php

namespace App\Services\Retrieval;

use App\Models\RetrievalChunk;

/**
 * Final selection after reranking: greedy by rank, dropping candidates
 * whose SOURCE coverage substantially overlaps an already-selected chunk
 * of the same asset (canonical-interval overlap — provenance, not string
 * equality — so the deliberate chunk overlap never shows up as five
 * near-identical "independent" results). Relevance-first: no artificial
 * per-book quotas.
 */
class CoverageSelector
{
    /**
     * @param  list<array{chunk: RetrievalChunk, ...}>  $ranked
     * @return array{selected: list<array>, dropped_duplicates: int}
     */
    public function select(array $ranked, int $topK, float $overlapRatio): array
    {
        $selected = [];
        $covered = []; // asset_id => list of [start, end]
        $dropped = 0;

        foreach ($ranked as $candidate) {
            if (count($selected) >= $topK) {
                break;
            }

            $chunk = $candidate['chunk'];
            $interval = [$chunk->canonical_start, $chunk->canonical_end];

            if ($this->overlapsExisting($covered[$chunk->book_asset_id] ?? [], $interval, $overlapRatio)) {
                $dropped++;

                continue;
            }

            $covered[$chunk->book_asset_id][] = $interval;
            $selected[] = $candidate;
        }

        return ['selected' => $selected, 'dropped_duplicates' => $dropped];
    }

    /** @param list<array{0: int, 1: int}> $intervals */
    private function overlapsExisting(array $intervals, array $candidate, float $ratio): bool
    {
        [$start, $end] = $candidate;
        $length = max(1, $end - $start);

        foreach ($intervals as [$existingStart, $existingEnd]) {
            $overlap = min($end, $existingEnd) - max($start, $existingStart);

            if ($overlap <= 0) {
                continue;
            }

            $smaller = min($length, max(1, $existingEnd - $existingStart));

            if ($overlap / $smaller >= $ratio) {
                return true;
            }
        }

        return false;
    }
}
