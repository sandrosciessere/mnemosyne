<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Library\StoreSubmissionRequest;
use App\Http\Resources\BookSubmissionResource;
use App\Models\BookSubmission;
use App\Services\Library\SubmissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SubmissionApiController extends Controller
{
    /** Cursor pagination: stable and index-friendly at library scale. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $submissions = BookSubmission::query()
            ->where('user_id', $request->user()->id)
            ->with(['latestRun', 'asset'])
            ->orderByDesc('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 25), 100));

        return BookSubmissionResource::collection($submissions);
    }

    public function store(StoreSubmissionRequest $request, SubmissionService $service): BookSubmissionResource
    {
        $submission = $service->createFromUpload(
            $request->user(),
            $request->file('epub'),
            $request->validated('note'),
        );

        return new BookSubmissionResource($submission->load(['latestRun', 'asset']));
    }

    public function show(Request $request, BookSubmission $submission): BookSubmissionResource
    {
        Gate::authorize('view', $submission);

        return new BookSubmissionResource($submission->load(['latestRun', 'asset']));
    }
}
