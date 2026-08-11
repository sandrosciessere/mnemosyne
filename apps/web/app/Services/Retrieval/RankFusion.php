<?php

namespace App\Services\Retrieval;

use App\Models\RetrievalChunk;

/**
 * Weighted Reciprocal Rank Fusion (fusion.version in the generation
 * config): score(d) = Σ_i w_i / (k + rank_i(d)). Rank-based — lexical
 * scores and cosine distances are never pretended comparable — and the
 * RRF score is NOT a probability. Deterministic tie-break: (asset
 * public_id, chunk ordinal).
 */
class RankFusion
{
    /**
     * @param  array<string, list<array{chunk: RetrievalChunk, rank: int}>>  $componentResults
     *                                                                                          component name → ranked candidates
     * @param  array{k: int|float, weights: array<string, float>}  $config
     * @return list<array{chunk: RetrievalChunk, rrf_score: float, components: array<string, array>}>
     */
    public function fuse(array $componentResults, array $config): array
    {
        $k = (float) $config['k'];
        $weights = $config['weights'];

        $byChunk = [];

        foreach ($componentResults as $component => $results) {
            $weight = (float) ($weights[$component] ?? 1.0);

            foreach ($results as $result) {
                $chunk = $result['chunk'];
                $key = $chunk->id;

                $byChunk[$key] ??= ['chunk' => $chunk, 'rrf_score' => 0.0, 'components' => []];
                $byChunk[$key]['rrf_score'] += $weight / ($k + $result['rank']);
                $byChunk[$key]['components'][$component] = array_diff_key($result, ['chunk' => null]);
            }
        }

        $fused = array_values($byChunk);

        usort($fused, function ($a, $b) {
            $byScore = $b['rrf_score'] <=> $a['rrf_score'];

            if ($byScore !== 0) {
                return $byScore;
            }

            return [$a['chunk']->asset->public_id ?? '', $a['chunk']->ordinal]
                <=> [$b['chunk']->asset->public_id ?? '', $b['chunk']->ordinal];
        });

        return $fused;
    }
}
