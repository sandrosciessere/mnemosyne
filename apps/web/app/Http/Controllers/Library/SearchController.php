<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\BookAccessGrant;
use App\Models\BookAsset;
use App\Models\Conversation;
use App\Models\RetrievalAssetState;
use App\Models\RetrievalGeneration;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform Search: grounded question answering over the user's
 * accessible books. Props carry the scope picker data (accessible +
 * retrieval-ready books) and recent conversations; questions are
 * submitted through the async answers API.
 */
class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $generation = RetrievalGeneration::active();

        $accessible = $user->isAdmin()
            ? BookAsset::query()->orderBy('id')->get()
            : BookAsset::query()
                ->whereIn('id', BookAccessGrant::query()->where('user_id', $user->id)->pluck('book_asset_id'))
                ->orderBy('id')
                ->get();

        $readyIds = $generation === null ? [] : RetrievalAssetState::query()
            ->where('retrieval_generation_id', $generation->id)
            ->where('status', 'ready')
            ->whereIn('book_asset_id', $accessible->pluck('id'))
            ->pluck('book_asset_id')
            ->all();

        $readySet = array_flip($readyIds);

        return Inertia::render('search', [
            'books' => $accessible->map(fn (BookAsset $asset) => [
                'public_id' => $asset->public_id,
                'title' => $asset->edition?->title ?? $asset->original_filename,
                'searchable' => isset($readySet[$asset->id]),
            ])->values()->all(),
            'answers_enabled' => $generation !== null,
            'conversations' => Conversation::query()
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity_at')
                ->limit(10)
                ->get()
                ->map(fn (Conversation $conversation) => [
                    'id' => $conversation->public_id,
                    'title' => $conversation->title,
                    'last_activity_at' => $conversation->last_activity_at?->toIso8601String(),
                ])->all(),
        ]);
    }
}
