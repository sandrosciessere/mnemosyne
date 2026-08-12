<?php

namespace App\Services\Retrieval;

use App\Models\BookAsset;
use App\Models\RetrievalGeneration;

/**
 * Development retrieval benchmark (NOT the future golden set): runs the
 * versioned case file against a generation and computes Recall@K and MRR
 * per mode. Relevance = the result's chunk text contains the expected
 * phrase in the expected book (deterministic, no graded judgments yet).
 * Comparing two generations = running this twice — no test-code change.
 */
class EvaluationRunner
{
    public function __construct(private readonly HybridSearchService $search) {}

    /**
     * @param  array  $cases  decoded evaluation-cases.json
     * @param  array<string, BookAsset>  $bookMap  book_ref → asset
     * @param  list<string>  $modes  subset of exact|lexical|dense|hybrid|hybrid+rerank
     * @return array{modes: array<string, array{recall_at_k: float, mrr: float, cases: int, found: int}>, per_case: array}
     */
    public function run(
        RetrievalGeneration $generation,
        array $cases,
        array $bookMap,
        array $modes,
        int $topK = 10,
    ): array {
        $assetIds = array_map(fn (BookAsset $asset) => $asset->id, array_values($bookMap));
        $metrics = [];
        $perCase = [];

        foreach ($modes as $modeLabel) {
            $mode = $modeLabel === 'hybrid+rerank' ? 'hybrid' : $modeLabel;
            $rerank = $modeLabel === 'hybrid+rerank';

            $found = 0;
            $reciprocalSum = 0.0;
            $evaluated = 0;

            foreach ($cases['cases'] as $case) {
                // Exact-only case types are meaningless for dense-only runs
                // and vice versa? No: every case runs in every mode — the
                // benchmark exists precisely to compare modes.
                if ($case['type'] === 'exact-absent') {
                    // Scored only in exact mode: any hit is a false positive.
                    if ($mode === 'exact') {
                        $outcome = $this->search->search(
                            $generation, $assetIds, $case['query'], 'exact', $topK, false,
                        );
                        $perCase[$modeLabel][$case['id']] = [
                            'false_positive' => $outcome['results'] !== [],
                        ];
                    }

                    continue;
                }

                $expectedAsset = $bookMap[$case['expected']['book_ref']];
                $phrase = $case['expected']['phrase'];

                $outcome = $this->search->search(
                    $generation, $assetIds, $case['query'], $mode, $topK, $rerank,
                );

                $rank = null;
                foreach ($outcome['results'] as $result) {
                    $chunk = $result['chunk'];

                    if ($chunk->book_asset_id === $expectedAsset->id
                        && str_contains($chunk->source_text, $phrase)) {
                        $rank = $result['final_rank'];
                        break;
                    }
                }

                $evaluated++;
                if ($rank !== null) {
                    $found++;
                    $reciprocalSum += 1 / $rank;
                }

                $perCase[$modeLabel][$case['id']] = ['rank' => $rank];
            }

            $metrics[$modeLabel] = [
                'recall_at_k' => $evaluated > 0 ? round($found / $evaluated, 4) : 0.0,
                'mrr' => $evaluated > 0 ? round($reciprocalSum / $evaluated, 4) : 0.0,
                'cases' => $evaluated,
                'found' => $found,
            ];
        }

        return ['modes' => $metrics, 'per_case' => $perCase, 'top_k' => $topK];
    }
}
