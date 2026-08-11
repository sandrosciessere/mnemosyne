<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\BookAsset;
use App\Services\Library\LibraryStorage;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookDownloadController extends Controller
{
    /**
     * Streams the immutable original EPUB. The client only ever supplies
     * the asset's public id — the storage path is resolved server-side
     * from the database, never from request input.
     */
    public function __invoke(BookAsset $asset, LibraryStorage $storage): StreamedResponse
    {
        Gate::authorize('download', $asset);

        abort_if($asset->storage_path === null, 404);
        abort_unless($storage->disk()->exists($asset->storage_path), 404);

        $filename = preg_replace('/[^\w\-. ]+/u', '_', $asset->original_filename) ?: 'book.epub';

        return $storage->disk()->download($asset->storage_path, $filename, [
            'Content-Type' => 'application/epub+zip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
