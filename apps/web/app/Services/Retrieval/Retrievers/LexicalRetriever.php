<?php

namespace App\Services\Retrieval\Retrievers;

use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL lexical retrieval over the generated weighted tsvector
 * ('simple' configuration: language-agnostic, multilingual-safe —
 * heading terms weight A, body terms weight B).
 *
 * Two-stage strategy (lexical version >= 1.1.0):
 *  - strict: websearch_to_tsquery — every term required. Precise for
 *    keyword queries, but natural-language queries fail because 'simple'
 *    keeps function words ("nel", "di") that rarely co-occur in a chunk.
 *  - or_fallback: ONLY when strict yields zero rows, meaningful query
 *    tokens (letters/numbers, >= 3 chars, deduped, capped) are combined
 *    with websearch OR syntax. Still fully parameterized — tokens are
 *    stripped to \p{L}\p{N} so no tsquery syntax can be injected, and
 *    websearch_to_tsquery never raises on user input.
 *
 * The strategy that produced the candidates is reported for the admin
 * debugger. Generations snapshotting lexical version 1.0.0 keep the
 * historical strict-only behavior (reproducibility).
 */
class LexicalRetriever
{
    private const FALLBACK_MIN_TOKEN_CHARS = 3;

    private const FALLBACK_MAX_TOKENS = 12;

    /**
     * @param  list<int>  $assetIds
     * @return array{candidates: list<array{chunk: RetrievalChunk, rank: int, score: float}>, strategy: string}
     */
    public function search(RetrievalGeneration $generation, array $assetIds, string $query, int $limit): array
    {
        if ($query === '' || $assetIds === []) {
            return ['candidates' => [], 'strategy' => 'none'];
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return ['candidates' => [], 'strategy' => 'none']; // lexical retrieval requires PostgreSQL FTS
        }

        $candidates = $this->run($generation, $assetIds, $query, $limit);

        if ($candidates !== []) {
            return ['candidates' => $candidates, 'strategy' => 'strict'];
        }

        $lexicalVersion = (string) ($generation->config['lexical']['version'] ?? '1.0.0');

        if (version_compare($lexicalVersion, '1.1.0', '<')) {
            return ['candidates' => [], 'strategy' => 'strict'];
        }

        $fallbackQuery = $this->orFallbackQuery($query);

        if ($fallbackQuery === null) {
            return ['candidates' => [], 'strategy' => 'strict'];
        }

        return [
            'candidates' => $this->run($generation, $assetIds, $fallbackQuery, $limit),
            'strategy' => 'or_fallback',
        ];
    }

    /**
     * Meaningful-token OR query in websearch syntax. Language-agnostic:
     * short tokens (typically articles/prepositions) are dropped rather
     * than consulting any language-specific stopword list; if nothing
     * survives the length filter, all tokens are used.
     */
    private function orFallbackQuery(string $query): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique($tokens));

        $meaningful = array_values(array_filter(
            $tokens,
            fn (string $token) => mb_strlen($token) >= self::FALLBACK_MIN_TOKEN_CHARS,
        ));

        $chosen = array_slice($meaningful !== [] ? $meaningful : $tokens, 0, self::FALLBACK_MAX_TOKENS);

        return $chosen === [] ? null : implode(' OR ', $chosen);
    }

    /** Allowlisted PostgreSQL text-search configuration for a generation. */
    public static function tsConfigFor(RetrievalGeneration $generation): string
    {
        $config = (string) ($generation->config['lexical']['config'] ?? 'simple');

        return in_array($config, ['simple', 'english', 'italian', 'french', 'german', 'spanish'], true)
            ? $config
            : 'simple';
    }

    /** @return list<array{chunk: RetrievalChunk, rank: int, score: float}> */
    private function run(RetrievalGeneration $generation, array $assetIds, string $tsQueryInput, int $limit): array
    {
        // Text-search config is OWNED by the generation profile (M2
        // backlog F26): the query-side config must equal the config the
        // tsvector was indexed with. Allowlisted identifiers only —
        // never interpolate arbitrary strings into SQL.
        $tsConfig = self::tsConfigFor($generation);

        // ts_rank_cd normalization 32: rank/(rank+1) → results in (0,1),
        // still NOT a calibrated probability (documented).
        $rows = DB::select(
            'SELECT id, ts_rank_cd(tsv, websearch_to_tsquery(\''.$tsConfig.'\', ?), 32) AS score
             FROM retrieval_chunks
             WHERE retrieval_generation_id = ?
               AND book_asset_id IN ('.implode(',', array_fill(0, count($assetIds), '?')).')
               AND tsv @@ websearch_to_tsquery(\''.$tsConfig.'\', ?)
             ORDER BY score DESC, book_asset_id ASC, ordinal ASC
             LIMIT ?',
            array_merge([$tsQueryInput, $generation->id], $assetIds, [$tsQueryInput, $limit]),
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
