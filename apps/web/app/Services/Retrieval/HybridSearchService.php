<?php

namespace App\Services\Retrieval;

use App\Exceptions\Library\WorkerUnavailableException;
use App\Models\BookAsset;
use App\Models\RetrievalAssetState;
use App\Models\RetrievalGeneration;
use App\Services\Ingestion\WorkerClient;
use App\Services\Retrieval\Reranker\WorkerRerankerProvider;
use App\Services\Retrieval\Retrievers\DenseRetriever;
use App\Services\Retrieval\Retrievers\ExactRetriever;
use App\Services\Retrieval\Retrievers\LexicalRetriever;
use Illuminate\Support\Facades\Log;

/**
 * The Milestone 2 query path:
 * scope → normalize → component retrievers → weighted RRF → rerank →
 * coverage/dedupe → ranked evidence. Every stage records its wall time;
 * a reranker failure degrades to fused order with an explicit
 * diagnostic flag (never silently pretended).
 */
class HybridSearchService
{
    public function __construct(
        private readonly QueryNormalizer $normalizer,
        private readonly ExactRetriever $exact,
        private readonly LexicalRetriever $lexical,
        private readonly DenseRetriever $dense,
        private readonly RankFusion $fusion,
        private readonly CoverageSelector $coverage,
        private readonly WorkerClient $worker,
    ) {}

    /**
     * @param  list<int>  $authorizedAssetIds  already ACL-filtered internal ids
     * @return array{results: list<array>, skipped_assets: list<string>, timings_ms: array<string, float>, diagnostics: array}
     */
    public function search(
        RetrievalGeneration $generation,
        array $authorizedAssetIds,
        string $query,
        string $mode,
        int $topK,
        bool $rerank,
        bool $caseSensitive = false,
    ): array {
        $timings = [];
        $diagnostics = [
            'generation' => $generation->public_id,
            'mode' => $mode,
            'reranker_used' => false,
            'reranker_fallback_reason' => null,
        ];

        // Per-book readiness: search only assets READY in this generation;
        // report the authorized-but-not-ready ones explicitly (partial
        // evidence is never returned silently).
        $t = hrtime(true);
        $readyAssetIds = RetrievalAssetState::query()
            ->where('retrieval_generation_id', $generation->id)
            ->whereIn('book_asset_id', $authorizedAssetIds)
            ->where('status', 'ready')
            ->pluck('book_asset_id')
            ->all();
        $skippedIds = array_values(array_diff($authorizedAssetIds, $readyAssetIds));
        $skippedPublicIds = $skippedIds === [] ? [] : BookAsset::query()
            ->whereIn('id', $skippedIds)->pluck('public_id')->all();
        $timings['scope'] = $this->ms($t);

        $config = $generation->config;
        $candidatesPerRetriever = (int) config('mnemosyne.retrieval.search.candidates_per_retriever');
        $components = [];

        if ($mode === 'exact' || $mode === 'hybrid') {
            $t = hrtime(true);
            $phrase = $this->normalizer->forExact($query);
            $components['exact'] = $this->exact->search(
                $generation, $readyAssetIds, $phrase, $caseSensitive, $candidatesPerRetriever,
            );
            $timings['exact'] = $this->ms($t);
        }

        if ($mode === 'lexical' || $mode === 'hybrid') {
            $t = hrtime(true);
            $components['lexical'] = $this->lexical->search(
                $generation, $readyAssetIds, $this->normalizer->forLexical($query), $candidatesPerRetriever,
            );
            $timings['lexical'] = $this->ms($t);
        }

        if ($mode === 'dense' || $mode === 'hybrid') {
            $t = hrtime(true);

            try {
                $components['dense'] = $this->dense->search(
                    $generation, $readyAssetIds, $this->normalizer->forDense($query), $candidatesPerRetriever,
                );
            } catch (WorkerUnavailableException $exception) {
                if ($mode === 'dense') {
                    throw $exception; // pure dense mode cannot degrade
                }

                // Hybrid degrades EXPLICITLY: exact+lexical continue, the
                // response says dense was unavailable.
                $components['dense'] = [];
                $diagnostics['dense_unavailable'] = true;
                Log::warning('retrieval.dense_unavailable', ['error' => $exception->getMessage()]);
            }
            $timings['dense'] = $this->ms($t);
        }

        $t = hrtime(true);
        $fused = $this->fusion->fuse($components, $config['fusion']);
        $timings['fusion'] = $this->ms($t);

        // Rerank the top M fused candidates (bounded CPU work).
        $rerankTopM = (int) config('mnemosyne.retrieval.search.rerank_top_m');

        if ($rerank && $fused !== []) {
            $t = hrtime(true);
            $fused = $this->rerankCandidates($generation, $query, $fused, $rerankTopM, $diagnostics);
            $timings['rerank'] = $this->ms($t);
        }

        $t = hrtime(true);
        $selection = $this->coverage->select(
            $fused,
            $topK,
            (float) config('mnemosyne.retrieval.search.dedupe_overlap_ratio'),
        );
        $timings['selection'] = $this->ms($t);
        $diagnostics['dropped_duplicates'] = $selection['dropped_duplicates'];

        $results = [];
        foreach ($selection['selected'] as $index => $candidate) {
            $results[] = $candidate + ['final_rank' => $index + 1];
        }

        $timings['total'] = array_sum($timings);

        return [
            'results' => $results,
            'skipped_assets' => $skippedPublicIds,
            'timings_ms' => array_map(fn ($value) => round($value, 2), $timings),
            'diagnostics' => $diagnostics,
        ];
    }

    /** @param list<array> $fused */
    private function rerankCandidates(
        RetrievalGeneration $generation,
        string $query,
        array $fused,
        int $topM,
        array &$diagnostics,
    ): array {
        $head = array_slice($fused, 0, $topM);
        $tail = array_slice($fused, $topM);

        $provider = new WorkerRerankerProvider(
            $this->worker,
            $generation->config['reranker']['model_key'],
        );

        try {
            $scores = $provider->rerank(
                mb_substr($query, 0, 2000),
                array_map(fn ($candidate) => [
                    'id' => (string) $candidate['chunk']->id,
                    'text' => $candidate['chunk']->source_text,
                ], $head),
            );
        } catch (\Throwable $exception) {
            // Honest degradation: fused order stands, flag says so.
            $diagnostics['reranker_used'] = false;
            $diagnostics['reranker_fallback_reason'] = $exception instanceof WorkerUnavailableException
                ? 'worker_unavailable'
                : 'reranker_error';
            Log::warning('retrieval.rerank_fallback', ['error' => $exception->getMessage()]);

            return $fused;
        }

        foreach ($head as &$candidate) {
            $candidate['rerank_score'] = $scores[(string) $candidate['chunk']->id] ?? null;
        }
        unset($candidate);

        // Stable sort by rerank score; candidates the model did not score
        // keep their fused order below the scored ones.
        usort($head, function ($a, $b) {
            $scoreA = $a['rerank_score'] ?? -INF;
            $scoreB = $b['rerank_score'] ?? -INF;

            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return $b['rrf_score'] <=> $a['rrf_score'];
        });

        $diagnostics['reranker_used'] = true;
        $diagnostics['reranker_model'] = $provider->modelIdentity();

        return array_merge($head, $tail);
    }

    private function ms(int|float $since): float
    {
        return (hrtime(true) - $since) / 1_000_000;
    }
}
