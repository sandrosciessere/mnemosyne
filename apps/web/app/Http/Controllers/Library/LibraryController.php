<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\BookAsset;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User library foundation: users see the books their access grants
 * cover; admins see the whole library. Collection ACLs will replace the
 * grant check in a later milestone.
 */
class LibraryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $assets = BookAsset::query()
            ->with(['edition.work', 'edition.contributors'])
            ->when(
                ! $user->isAdmin(),
                fn ($query) => $query->whereHas(
                    'accessGrants',
                    fn ($grants) => $grants->where('user_id', $user->id),
                ),
            )
            ->latest('id')
            ->paginate(24)
            ->withQueryString();

        return Inertia::render('library', [
            'is_admin' => $user->isAdmin(),
            'books' => $assets->through(fn (BookAsset $asset) => [
                'public_id' => $asset->public_id,
                'title' => $asset->edition?->title
                    ?? $asset->extracted_metadata['title']
                    ?? $asset->original_filename,
                'authors' => $asset->edition?->contributors
                    ->filter(fn ($contributor) => $contributor->pivot->role === 'aut')
                    ->map(fn ($contributor) => $contributor->pivot->credited_as)
                    ->values()->all() ?? [],
                'language' => $asset->edition?->language,
                'ingestion_status' => $asset->ingestion_status->value,
                'epub_version' => $asset->epub_version,
                'can_download' => $user->can('download', $asset),
            ]),
        ]);
    }
}
