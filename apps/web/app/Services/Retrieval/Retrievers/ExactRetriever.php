<?php

namespace App\Services\Retrieval\Retrievers;

use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use Illuminate\Support\Facades\DB;

/**
 * Literal source-text retrieval. Parameterized LIKE/ILIKE with escaped
 * wildcards (no regex, no injection) backed by the trigram GIN index on
 * PostgreSQL. Chunk overlap guarantees phrases straddling the chunk
 * partition (up to overlap_tail_chars) are found intact; results carry
 * exact per-match offsets mapped back to canonical coordinates through
 * the chunk's evidence spans.
 */
class ExactRetriever
{
    private const MATCHES_PER_CHUNK = 3;

    /**
     * @param  list<int>  $assetIds
     * @return list<array{chunk: RetrievalChunk, rank: int, matches: list<array{chunk_start: int, chunk_end: int, canonical_start: int|null, canonical_end: int|null, text: string}>}>
     */
    public function search(
        RetrievalGeneration $generation,
        array $assetIds,
        string $phrase,
        bool $caseSensitive,
        int $limit,
    ): array {
        if ($phrase === '' || $assetIds === []) {
            return [];
        }

        $pattern = '%'.self::escapeLike($phrase).'%';
        $operator = $caseSensitive ? 'like' : 'ilike';

        // SQLite (fast unit suite) has no ILIKE; its LIKE is already
        // case-insensitive for ASCII. Behavioral guarantees are proven on
        // real PostgreSQL in the integration suite.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $operator = 'like';
        }

        $chunks = RetrievalChunk::query()
            ->where('retrieval_generation_id', $generation->id)
            ->whereIn('book_asset_id', $assetIds)
            ->where('source_text', $operator, $pattern)
            ->orderBy('book_asset_id')
            ->orderBy('ordinal')
            ->limit($limit)
            ->with('spans')
            ->get();

        $results = [];
        $rank = 1;

        foreach ($chunks as $chunk) {
            $matches = $this->locateMatches($chunk, $phrase, $caseSensitive);

            if ($matches === []) {
                continue; // e.g. case-sensitive miss on the sqlite fallback
            }

            $results[] = ['chunk' => $chunk, 'rank' => $rank++, 'matches' => $matches];
        }

        return $results;
    }

    /** Escape LIKE wildcards so the phrase is strictly literal. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @return list<array> */
    private function locateMatches(RetrievalChunk $chunk, string $phrase, bool $caseSensitive): array
    {
        $haystack = $caseSensitive ? $chunk->source_text : mb_strtolower($chunk->source_text);
        $needle = $caseSensitive ? $phrase : mb_strtolower($phrase);
        $length = mb_strlen($phrase);

        $matches = [];
        $offset = 0;

        while (count($matches) < self::MATCHES_PER_CHUNK) {
            $position = mb_strpos($haystack, $needle, $offset);

            if ($position === false) {
                break;
            }

            $matches[] = [
                'chunk_start' => $position,
                'chunk_end' => $position + $length,
                'canonical_start' => $this->toCanonical($chunk, $position),
                'canonical_end' => $this->toCanonical($chunk, $position + $length, end: true),
                'text' => mb_substr($chunk->source_text, $position, $length),
            ];

            $offset = $position + 1;
        }

        return $matches;
    }

    /**
     * Chunk-local position → canonical offset via the covering span.
     * Positions on synthetic separators map through the nearest source
     * character (start: next span; end: previous span's coverage).
     */
    private function toCanonical(RetrievalChunk $chunk, int $position, bool $end = false): ?int
    {
        foreach ($chunk->spans as $span) {
            if ($end) {
                if ($position > $span->chunk_start && $position <= $span->chunk_end) {
                    return $span->canonical_start + ($position - $span->chunk_start);
                }
            } elseif ($position >= $span->chunk_start && $position < $span->chunk_end) {
                return $span->canonical_start + ($position - $span->chunk_start);
            }
        }

        // Separator position: snap forward (start) / backward (end).
        foreach ($chunk->spans as $span) {
            if (! $end && $span->chunk_start >= $position) {
                return $span->canonical_start;
            }
        }

        foreach (array_reverse($chunk->spans->all()) as $span) {
            if ($end && $span->chunk_end <= $position) {
                return $span->canonical_end;
            }
        }

        return null;
    }
}
