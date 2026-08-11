<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\Library\StoreSubmissionRequest;
use App\Models\BookSubmission;
use App\Services\Ingestion\RunPresentation;
use App\Services\Library\SubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SubmissionController extends Controller
{
    public function index(Request $request): Response
    {
        $submissions = BookSubmission::query()
            ->where('user_id', $request->user()->id)
            ->with(['latestRun', 'asset'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('library/submissions/index', [
            'submissions' => $submissions->through(fn (BookSubmission $submission) => [
                'public_id' => $submission->public_id,
                'original_filename' => $submission->original_filename,
                'status' => $submission->derivedStatus(),
                'note' => $submission->note,
                'rejection_reason' => $submission->rejection_reason,
                'progress' => $submission->latestRun?->progress ?? 0,
                'current_stage' => $submission->latestRun?->current_stage?->value,
                'is_exact_duplicate' => $submission->is_exact_duplicate,
                'created_at' => $submission->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', BookSubmission::class);

        return Inertia::render('library/submissions/create', [
            'maxUploadBytes' => (int) config('mnemosyne.ingestion.max_upload_bytes'),
        ]);
    }

    public function store(StoreSubmissionRequest $request, SubmissionService $service): RedirectResponse
    {
        $submission = $service->createFromUpload(
            $request->user(),
            $request->file('epub'),
            $request->validated('note'),
        );

        return to_route('library.submissions.show', $submission)
            ->with('success', 'EPUB submitted.');
    }

    public function show(Request $request, BookSubmission $submission): Response
    {
        Gate::authorize('view', $submission);

        $submission->load([
            'latestRun.attempts',
            'asset.edition.work',
            'asset.edition.contributors',
            'events' => fn ($query) => $query->latest('id')->limit(100),
        ]);

        $run = $submission->latestRun;
        $asset = $submission->asset;

        return Inertia::render('library/submissions/show', [
            'submission' => [
                'public_id' => $submission->public_id,
                'original_filename' => $submission->original_filename,
                'note' => $submission->note,
                'status' => $submission->derivedStatus(),
                'approval_status' => $submission->status->value,
                'rejection_reason' => $submission->rejection_reason,
                'is_exact_duplicate' => $submission->is_exact_duplicate,
                'created_at' => $submission->created_at->toIso8601String(),
                'can_cancel' => $request->user()->can('cancel', $submission),
            ],
            'run' => $run === null ? null : [
                'public_id' => $run->public_id,
                'status' => $run->status->value,
                'current_stage' => $run->current_stage?->value,
                'progress' => $run->progress,
                'pipeline_stages' => RunPresentation::pipelineStages($run),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'error_code' => $run->last_error_code,
                'error_message' => $run->last_error_message,
                'review_issues' => collect($run->review_issues ?? [])->map(fn ($issue) => [
                    'code' => $issue['code'],
                    'message' => $issue['message'],
                ])->all(),
            ],
            'asset' => $asset === null ? null : [
                'public_id' => $asset->public_id,
                'ingestion_status' => $asset->ingestion_status->value,
                'epub_version' => $asset->epub_version,
                'structure_summary' => $asset->structure_summary,
                'title' => $asset->edition?->title,
                'work_title' => $asset->edition?->work?->canonical_title,
                'contributors' => $asset->edition?->contributors
                    ->map(fn ($contributor) => [
                        'name' => $contributor->pivot->credited_as,
                        'role' => $contributor->pivot->role,
                    ])->all() ?? [],
                'can_download' => $request->user()->can('download', $asset),
            ],
            'events' => $submission->events->reverse()->values()->map(fn ($event) => [
                'type' => $event->type,
                'payload' => $event->payload,
                'created_at' => $event->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function cancel(Request $request, BookSubmission $submission, SubmissionService $service): RedirectResponse
    {
        Gate::authorize('cancel', $submission);

        $service->cancel($submission, $request->user());

        return back()->with('success', 'Submission cancelled.');
    }
}
