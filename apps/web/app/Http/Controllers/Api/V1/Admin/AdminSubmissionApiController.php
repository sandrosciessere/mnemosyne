<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookSubmissionResource;
use App\Models\BookSubmission;
use App\Services\Library\SubmissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminSubmissionApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $submissions = BookSubmission::query()
            ->with(['latestRun', 'asset', 'user:id,name,email'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->orderByDesc('id')
            ->cursorPaginate(max(1, min((int) $request->integer('per_page', 25), 100)));

        return BookSubmissionResource::collection($submissions);
    }

    public function approve(Request $request, BookSubmission $submission, SubmissionService $service): BookSubmissionResource
    {
        $service->approve($submission, $request->user());

        return new BookSubmissionResource($submission->refresh()->load(['latestRun', 'asset']));
    }

    public function reject(Request $request, BookSubmission $submission, SubmissionService $service): BookSubmissionResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $service->reject($submission, $request->user(), $validated['reason']);

        return new BookSubmissionResource($submission->refresh()->load(['latestRun', 'asset']));
    }
}
