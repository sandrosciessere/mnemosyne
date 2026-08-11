<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookAsset;
use App\Models\Work;
use App\Services\Ingestion\RunPresentation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Minimal but real admin navigation over Work → Edition → Asset.
 * Bibliographic editing arrives in a later milestone.
 */
class LibraryAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('q')->toString();

        $works = Work::query()
            ->withCount('editions')
            ->when($search !== '', fn ($query) => $query->where(
                'normalized_title',
                'like',
                '%'.Work::normalizeTitle($search).'%',
            ))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/library/index', [
            'filters' => ['q' => $search],
            'works' => $works->through(fn (Work $work) => [
                'public_id' => $work->public_id,
                'title' => $work->canonical_title,
                'language' => $work->original_language,
                'status' => $work->status->value,
                'editions_count' => $work->editions_count,
                'created_at' => $work->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function work(Work $work): Response
    {
        $work->load([
            'editions.contributors',
            'editions.identifiers',
            'editions.assets:id,edition_id,public_id,original_filename,sha256,ingestion_status,size_bytes,epub_version',
        ]);

        return Inertia::render('admin/library/work', [
            'work' => [
                'public_id' => $work->public_id,
                'title' => $work->canonical_title,
                'normalized_title' => $work->normalized_title,
                'language' => $work->original_language,
                'status' => $work->status->value,
                'reconciliation' => $work->reconciliation,
                'created_at' => $work->created_at->toIso8601String(),
            ],
            'editions' => $work->editions->map(fn ($edition) => [
                'public_id' => $edition->public_id,
                'title' => $edition->title,
                'subtitle' => $edition->subtitle,
                'language' => $edition->language,
                'publisher' => $edition->publisher,
                'publication_year' => $edition->publication_year,
                'status' => $edition->status->value,
                'contributors' => $edition->contributors->map(fn ($contributor) => [
                    'name' => $contributor->pivot->credited_as,
                    'role' => $contributor->pivot->role,
                ])->all(),
                'identifiers' => $edition->identifiers->map(fn ($identifier) => [
                    'scheme' => $identifier->scheme,
                    'value' => $identifier->value,
                ])->all(),
                'assets' => $edition->assets->map(fn ($asset) => [
                    'public_id' => $asset->public_id,
                    'original_filename' => $asset->original_filename,
                    'sha256' => $asset->sha256,
                    'ingestion_status' => $asset->ingestion_status->value,
                    'size_bytes' => $asset->size_bytes,
                    'epub_version' => $asset->epub_version,
                ])->all(),
            ]),
        ]);
    }

    public function asset(BookAsset $asset): Response
    {
        $asset->load([
            'edition.work',
            'submissions.user:id,name,email',
            'runs' => fn ($query) => $query->latest('id')->limit(20),
            'duplicateCandidates.duplicateOf:id,public_id,original_filename',
            'duplicateOfCandidates.asset:id,public_id,original_filename',
        ]);

        return Inertia::render('admin/library/asset', [
            'warnings_summary' => RunPresentation::warningsForAsset($asset),
            'asset' => [
                'public_id' => $asset->public_id,
                'original_filename' => $asset->original_filename,
                'sha256' => $asset->sha256,
                'content_sha256' => $asset->content_sha256,
                'storage_path' => $asset->storage_path,
                'size_bytes' => $asset->size_bytes,
                'uncompressed_size_bytes' => $asset->uncompressed_size_bytes,
                'epub_version' => $asset->epub_version,
                'validation_status' => $asset->validation_status,
                'ingestion_status' => $asset->ingestion_status->value,
                'pipeline_version' => $asset->pipeline_version,
                'metadata' => $asset->extracted_metadata,
                'structure_summary' => $asset->structure_summary,
                'reconciliation' => $asset->reconciliation,
                'created_at' => $asset->created_at->toIso8601String(),
                'edition' => $asset->edition === null ? null : [
                    'public_id' => $asset->edition->public_id,
                    'title' => $asset->edition->title,
                    'work_public_id' => $asset->edition->work->public_id,
                    'work_title' => $asset->edition->work->canonical_title,
                ],
            ],
            'submissions' => $asset->submissions->map(fn ($submission) => [
                'public_id' => $submission->public_id,
                'submitter' => $submission->user?->only(['name', 'email']),
                'source_type' => $submission->source_type->value,
                'is_exact_duplicate' => $submission->is_exact_duplicate,
                'created_at' => $submission->created_at->toIso8601String(),
            ]),
            'runs' => $asset->runs->map(fn ($run) => [
                'public_id' => $run->public_id,
                'status' => $run->status->value,
                'pipeline_version' => $run->pipeline_version,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ]),
            'duplicates' => collect()
                ->concat($asset->duplicateCandidates)
                ->concat($asset->duplicateOfCandidates)
                ->map(fn ($candidate) => [
                    'public_id' => $candidate->public_id,
                    'reason' => $candidate->reason,
                    'status' => $candidate->status->value,
                    'other_asset' => $candidate->book_asset_id === $asset->id
                        ? $candidate->duplicateOf?->only(['public_id', 'original_filename'])
                        : $candidate->asset?->only(['public_id', 'original_filename']),
                ])->values(),
        ]);
    }
}
