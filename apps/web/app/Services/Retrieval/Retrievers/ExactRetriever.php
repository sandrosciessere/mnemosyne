<?php

namespace App\Services\Retrieval\Retrievers;

use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /** Whether the last search hit the result cap (more chunks matched). */
    public bool $lastTruncated = false;

    /** Whether the last phrase was shorter than 3 chars (precision caveat). */
    public bool $lastQueryShort = false;

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

        // Recall (M2 backlog F5/F6/F17): canonical M1 text is NFC. A user
        // literal typed/pasted in NFD ("e" + U+0301) would silently miss
        // its NFC source occurrence — normalize the PHRASE to NFC before
        // matching. Source coordinates stay untouched (they are located
        // in the original source string below).
        if (class_exists(\Normalizer::class)) {
            $phrase = \Normalizer::normalize($phrase, \Normalizer::FORM_C) ?: $phrase;
        }

        // Very short literals (M2 backlog F22): sub-3-char patterns are
        // legal but scale badly (trigram index unusable, LIKE degenerates
        // to a scan on large scopes). They are still served — bounded by
        // $limit — and flagged so callers can explain low precision.
        $this->lastQueryShort = mb_strlen($phrase) < 3;

        $pattern = '%'.self::escapeLike($phrase).'%';
        $operator = $caseSensitive ? 'like' : 'ilike';

        // SQLite (fast unit suite) has no ILIKE; its LIKE is already
        // case-insensitive for ASCII. Behavioral guarantees are proven on
        // real PostgreSQL in the integration suite.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $operator = 'like';
        }

        // Fetch one extra row to detect truncation honestly (F17).
        $chunks = RetrievalChunk::query()
            ->where('retrieval_generation_id', $generation->id)
            ->whereIn('book_asset_id', $assetIds)
            ->where('source_text', $operator, $pattern)
            ->orderBy('book_asset_id')
            ->orderBy('ordinal')
            ->limit($limit + 1)
            ->with('spans')
            ->get();

        $this->lastTruncated = $chunks->count() > $limit;
        $chunks = $chunks->take($limit);

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
        $source = $chunk->source_text;
        $length = mb_strlen($phrase);
        $foldedPhrase = $caseSensitive ? null : mb_strtolower($phrase);

        $matches = [];
        $offset = 0;

        while (count($matches) < self::MATCHES_PER_CHUNK) {
            // Offsets are ALWAYS located in the original source string.
            // Unicode case folding is not length-preserving (e.g. İ →
            // i+U+0307), so positions must never be derived from a folded
            // copy of the haystack: mb_stripos searches case-insensitively
            // while returning original-string coordinates.
            $position = $caseSensitive
                ? mb_strpos($source, $phrase, $offset)
                : mb_stripos($source, $phrase, $offset);

            if ($position === false) {
                break;
            }

            $text = mb_substr($source, $position, $length);

            // Defense in depth: never emit provenance whose original-source
            // slice does not equal the requested literal under the selected
            // case semantics (a length-changing fold INSIDE the match could
            // make the fixed-length slice diverge — skip, don't lie).
            $valid = $caseSensitive
                ? $text === $phrase
                : mb_strtolower($text) === $foldedPhrase;

            if ($valid) {
                $matches[] = [
                    'chunk_start' => $position,
                    'chunk_end' => $position + $length,
                    'canonical_start' => $this->toCanonical($chunk, $position),
                    'canonical_end' => $this->toCanonical($chunk, $position + $length, end: true),
                    'text' => $text,
                ];
            } else {
                Log::debug('retrieval.exact_match_fold_skip', [
                    'chunk' => $chunk->public_id,
                    'position' => $position,
                ]);
            }

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
