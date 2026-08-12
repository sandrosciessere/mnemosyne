<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookAccessGrant;
use App\Models\BookAsset;
use App\Models\RetrievalAssetState;
use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use App\Services\Retrieval\HybridSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetrievalSearchController extends Controller
{
    /**
     * POST /api/v1/retrieval/search — ranked evidence with exact source
     * provenance. ACL is enforced server-side BEFORE retrieval: users
     * search only assets they hold grants for; requesting any
     * inaccessible/unknown asset id fails closed (no oracle about which).
     */
    public function search(Request $request, HybridSearchService $service): JsonResponse
    {
        $maxTopK = (int) config('mnemosyne.retrieval.search.max_top_k');

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:'.config('mnemosyne.retrieval.search.max_query_chars')],
            'mode' => ['sometimes', 'in:exact,lexical,dense,hybrid'],
            'scope' => ['sometimes', 'array'],
            'scope.book_asset_ids' => ['sometimes', 'array', 'max:100'],
            'scope.book_asset_ids.*' => ['string', 'regex:/^[0-9a-z]{26}$/'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:'.$maxTopK],
            'rerank' => ['sometimes', 'boolean'],
            'case_sensitive' => ['sometimes', 'boolean'],
            'debug' => ['sometimes', 'boolean'],
        ]);

        if (trim($validated['query']) === '') {
            return $this->errorResponse('QUERY_EMPTY', 'Query must not be blank.', 422);
        }

        // Exact mode accepts only literals short enough for the chunk-
        // boundary guarantee (pre-boundary portion <= chunker overlap).
        // Never silently run an exact search with a known false-negative
        // window.
        $maxExact = (int) config('mnemosyne.retrieval.search.max_exact_phrase_chars');

        if (($validated['mode'] ?? 'hybrid') === 'exact' && mb_strlen(trim($validated['query'])) > $maxExact) {
            return $this->errorResponse(
                'EXACT_QUERY_TOO_LONG',
                "Exact phrases are limited to {$maxExact} characters (boundary guarantee).",
                422,
            );
        }

        $generation = RetrievalGeneration::active();

        if ($generation === null) {
            return $this->errorResponse('NO_ACTIVE_GENERATION', 'No retrieval generation is active.', 409);
        }

        $user = $request->user();
        $assetIds = $this->resolveScope($user, $validated['scope']['book_asset_ids'] ?? null, $generation);

        if ($assetIds === null) {
            return $this->errorResponse(
                'SCOPE_NOT_ACCESSIBLE',
                'One or more requested books are not accessible.',
                403,
            );
        }

        $debug = (bool) ($validated['debug'] ?? false) && $user->isAdmin();

        $outcome = $service->search(
            $generation,
            $assetIds,
            $validated['query'],
            $validated['mode'] ?? 'hybrid',
            (int) ($validated['top_k'] ?? config('mnemosyne.retrieval.search.default_top_k')),
            // Reranking is opt-in (independent review: ~3.5 s CPU latency
            // for a mixed quality delta) — explicit rerank:true enables it.
            (bool) ($validated['rerank'] ?? false),
            (bool) ($validated['case_sensitive'] ?? false),
        );

        return response()->json([
            'data' => array_map(
                fn ($candidate) => $this->presentResult($candidate, $debug),
                $outcome['results'],
            ),
            'meta' => array_filter([
                'generation' => $generation->public_id,
                'mode' => $validated['mode'] ?? 'hybrid',
                'skipped_assets' => $outcome['skipped_assets'],
                'reranker_used' => $outcome['diagnostics']['reranker_used'],
                'reranker_fallback_reason' => $outcome['diagnostics']['reranker_fallback_reason'],
                'dense_unavailable' => $outcome['diagnostics']['dense_unavailable'] ?? false,
                'exact_skipped_reason' => $outcome['diagnostics']['exact_skipped_reason'] ?? null,
                'timings_ms' => $debug ? $outcome['timings_ms'] : null,
                'diagnostics' => $debug ? $outcome['diagnostics'] : null,
            ], fn ($value) => $value !== null),
        ]);
    }

    /**
     * GET /api/v1/retrieval/chunks/{chunk}/neighbors — context expansion
     * (previous/next chunk), authorization identical to search results.
     */
    public function neighbors(Request $request, RetrievalChunk $chunk): JsonResponse
    {
        $asset = $chunk->asset;

        if (! $request->user()->isAdmin() && ! $request->user()->can('view', $asset)) {
            abort(403);
        }

        $neighbors = RetrievalChunk::query()
            ->where('retrieval_generation_id', $chunk->retrieval_generation_id)
            ->where('book_asset_id', $chunk->book_asset_id)
            ->whereIn('ordinal', [$chunk->ordinal - 1, $chunk->ordinal + 1])
            ->with('spans')
            ->orderBy('ordinal')
            ->get();

        return response()->json([
            'data' => [
                'previous' => $this->presentNeighbor($neighbors->firstWhere('ordinal', $chunk->ordinal - 1)),
                'next' => $this->presentNeighbor($neighbors->firstWhere('ordinal', $chunk->ordinal + 1)),
            ],
        ]);
    }

    /**
     * @return list<int>|null internal asset ids, or null when the request
     *                        includes an inaccessible/unknown asset
     */
    private function resolveScope($user, ?array $requestedPublicIds, RetrievalGeneration $generation): ?array
    {
        if ($requestedPublicIds !== null) {
            $assets = BookAsset::query()
                ->whereIn('public_id', $requestedPublicIds)
                ->get(['id', 'public_id']);

            if ($assets->count() !== count(array_unique($requestedPublicIds))) {
                return null; // unknown ids fail closed, indistinguishable
            }

            if (! $user->isAdmin()) {
                $grantedCount = BookAccessGrant::query()
                    ->where('user_id', $user->id)
                    ->whereIn('book_asset_id', $assets->pluck('id'))
                    ->count();

                if ($grantedCount !== $assets->count()) {
                    return null;
                }
            }

            return $assets->pluck('id')->all();
        }

        // No explicit scope: every authorized asset. Admin = all assets
        // ready in the generation (kept as an id list for now; the 100k
        // refinement moves this predicate into the retriever SQL).
        if ($user->isAdmin()) {
            return RetrievalAssetState::query()
                ->where('retrieval_generation_id', $generation->id)
                ->where('status', 'ready')
                ->pluck('book_asset_id')
                ->all();
        }

        return BookAccessGrant::query()
            ->where('user_id', $user->id)
            ->pluck('book_asset_id')
            ->all();
    }

    private function presentResult(array $candidate, bool $debug): array
    {
        $chunk = $candidate['chunk'];
        $excerptSource = mb_substr($chunk->source_text, $chunk->overlap_prefix_chars);

        $result = [
            'rank' => $candidate['final_rank'],
            'chunk_id' => $chunk->public_id,
            'book_asset_id' => $chunk->asset->public_id,
            'book' => [
                'title' => $chunk->asset->edition?->title
                    ?? $chunk->asset->extracted_metadata['title']
                    ?? $chunk->asset->original_filename,
                'work_title' => $chunk->asset->edition?->work?->canonical_title,
            ],
            'heading_path' => $chunk->heading_path ?? [],
            'spine_index' => $chunk->spine_index,
            'excerpt' => mb_substr($excerptSource, 0, 700),
            'excerpt_truncated' => mb_strlen($excerptSource) > 700,
            'char_count' => $chunk->char_count,
            'evidence_spans' => $chunk->spans->map(
                fn ($span) => $span->toProvenanceArray(),
            )->all(),
            'exact_matches' => array_map(fn ($match) => [
                'text' => $match['text'],
                'chunk_start' => $match['chunk_start'],
                'chunk_end' => $match['chunk_end'],
                'canonical_start' => $match['canonical_start'],
                'canonical_end' => $match['canonical_end'],
            ], $candidate['components']['exact']['matches'] ?? []),
        ];

        if ($debug) {
            $result['scores'] = [
                'exact_rank' => $candidate['components']['exact']['rank'] ?? null,
                'lexical_rank' => $candidate['components']['lexical']['rank'] ?? null,
                'lexical_score' => $candidate['components']['lexical']['score'] ?? null,
                'dense_rank' => $candidate['components']['dense']['rank'] ?? null,
                'dense_similarity' => $candidate['components']['dense']['similarity'] ?? null,
                'rrf_score' => $candidate['rrf_score'] ?? null,
                'rerank_score' => $candidate['rerank_score'] ?? null,
            ];
        }

        return $result;
    }

    private function presentNeighbor(?RetrievalChunk $chunk): ?array
    {
        if ($chunk === null) {
            return null;
        }

        return [
            'chunk_id' => $chunk->public_id,
            'ordinal' => $chunk->ordinal,
            'heading_path' => $chunk->heading_path ?? [],
            'excerpt' => mb_substr(mb_substr($chunk->source_text, $chunk->overlap_prefix_chars), 0, 700),
            'evidence_spans' => $chunk->spans->map(fn ($span) => $span->toProvenanceArray())->all(),
        ];
    }

    private function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message, 'details' => (object) []],
        ], $status);
    }
}
