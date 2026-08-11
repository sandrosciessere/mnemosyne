<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookAsset;
use App\Models\RetrievalChunk;
use App\Models\RetrievalEmbedding;
use App\Models\RetrievalGeneration;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RetrievalAdminController extends Controller
{
    public function index(): Response
    {
        $generations = RetrievalGeneration::query()->latest('id')->limit(10)->get();

        return Inertia::render('admin/retrieval/index', [
            'generations' => $generations->map(function (RetrievalGeneration $generation) {
                $states = $generation->assetStates()
                    ->select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')
                    ->pluck('total', 'status');

                $embedding = $generation->config['embedding'] ?? [];

                return [
                    'public_id' => $generation->public_id,
                    'status' => $generation->status,
                    'chunker_version' => $generation->chunker_version,
                    'chunker_config' => $generation->config['chunker']['config'] ?? [],
                    'embedding' => [
                        'model_key' => $generation->embedding_model_key,
                        'hf_id' => $embedding['hf_id'] ?? null,
                        'revision' => $embedding['revision'] ?? null,
                        'dimensions' => $generation->embedding_dimensions,
                        'metric' => $embedding['metric'] ?? 'cosine',
                    ],
                    'reranker' => $generation->config['reranker'] ?? null,
                    'fusion' => $generation->config['fusion'] ?? null,
                    'assets' => [
                        'ready' => (int) ($states['ready'] ?? 0),
                        'pending' => (int) ($states['pending'] ?? 0),
                        'chunking' => (int) ($states['chunking'] ?? 0),
                        'embedding' => (int) ($states['embedding'] ?? 0),
                        'failed' => (int) ($states['failed'] ?? 0),
                    ],
                    'chunks' => RetrievalChunk::query()
                        ->where('retrieval_generation_id', $generation->id)->count(),
                    'embeddings' => RetrievalEmbedding::query()
                        ->where('retrieval_generation_id', $generation->id)->count(),
                    'activated_at' => $generation->activated_at?->toIso8601String(),
                    'recent_failures' => $generation->assetStates()
                        ->where('status', 'failed')
                        ->with('asset:id,public_id,original_filename')
                        ->latest('updated_at')
                        ->limit(5)
                        ->get()
                        ->map(fn ($state) => [
                            'asset' => $state->asset?->public_id,
                            'filename' => $state->asset?->original_filename,
                            'error_code' => $state->last_error_code,
                            'error_message' => $state->last_error_message,
                            'attempts' => $state->attempts,
                        ])->all(),
                ];
            }),
            'eligible_assets' => BookAsset::query()
                ->whereIn('ingestion_status', ['ready_for_enrichment', 'ready_for_enrichment_with_warnings'])
                ->count(),
        ]);
    }

    /** Admin search debugger shell: the page queries the retrieval API. */
    public function debugger(): Response
    {
        $active = RetrievalGeneration::active();

        $books = $active === null ? collect() : BookAsset::query()
            ->whereIn('id', $active->assetStates()->where('status', 'ready')->pluck('book_asset_id'))
            ->with('edition.work')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (BookAsset $asset) => [
                'public_id' => $asset->public_id,
                'title' => $asset->edition?->title
                    ?? $asset->extracted_metadata['title']
                    ?? $asset->original_filename,
            ]);

        return Inertia::render('admin/retrieval/debugger', [
            'active_generation' => $active?->public_id,
            'embedding_model' => $active?->embedding_model_key,
            'reranker_model' => $active?->config['reranker']['model_key'] ?? null,
            'books' => $books,
        ]);
    }
}
