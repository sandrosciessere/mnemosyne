<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookSubmission;
use App\Services\Library\SubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubmissionAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $submissions = BookSubmission::query()
            ->with(['user:id,name,email', 'latestRun', 'asset:id,public_id,sha256'])
            ->when(
                $status !== '' && $status !== 'all',
                function ($query) use ($status) {
                    // Approval statuses filter directly; run-derived ones join.
                    return match ($status) {
                        'pending_approval', 'rejected', 'cancelled' => $query->where('status', $status),
                        'queued', 'running', 'needs_review', 'failed' => $query
                            ->where('status', 'approved')
                            ->whereHas('latestRun', fn ($runs) => $runs->where('status', $status)),
                        'completed' => $query
                            ->where('status', 'approved')
                            ->whereHas('latestRun', fn ($runs) => $runs->where('status', 'succeeded')),
                        default => $query,
                    };
                },
            )
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/submissions', [
            'filters' => ['status' => $status ?: 'all'],
            'pending_count' => BookSubmission::query()->where('status', 'pending_approval')->count(),
            'submissions' => $submissions->through(fn (BookSubmission $submission) => [
                'public_id' => $submission->public_id,
                'original_filename' => $submission->original_filename,
                'status' => $submission->derivedStatus(),
                'source_type' => $submission->source_type->value,
                'submitter' => $submission->user?->only(['name', 'email']),
                'priority' => $submission->priority->value,
                'note' => $submission->note,
                'is_exact_duplicate' => $submission->is_exact_duplicate,
                'run_public_id' => $submission->latestRun?->public_id,
                'created_at' => $submission->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function approve(Request $request, BookSubmission $submission, SubmissionService $service): RedirectResponse
    {
        $service->approve($submission, $request->user());

        return back()->with('success', 'Submission approved and queued.');
    }

    public function reject(Request $request, BookSubmission $submission, SubmissionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $service->reject($submission, $request->user(), $validated['reason']);

        return back()->with('success', 'Submission rejected.');
    }
}
