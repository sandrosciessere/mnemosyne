<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IngestionPriority;
use App\Http\Controllers\Controller;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use App\Services\Ingestion\IngestionOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProcessingController extends Controller
{
    public function index(): Response
    {
        $runCounts = IngestionRun::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $queueDepths = IngestionRun::query()
            ->where('status', 'queued')
            ->select('priority', DB::raw('count(*) as total'))
            ->groupBy('priority')
            ->pluck('total', 'priority');

        $stageDistribution = IngestionRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNotNull('current_stage')
            ->select('current_stage', DB::raw('count(*) as total'))
            ->groupBy('current_stage')
            ->pluck('total', 'current_stage');

        $oldestQueued = IngestionRun::query()
            ->where('status', 'queued')
            ->orderBy('queued_at')
            ->first();

        $recentFailures = IngestionRun::query()
            ->with('submission:id,public_id,original_filename')
            ->whereIn('status', ['failed', 'needs_review'])
            ->latest('finished_at')
            ->latest('id')
            ->limit(10)
            ->get();

        $recentCompletions = IngestionRun::query()
            ->with('submission:id,public_id,original_filename')
            ->where('status', 'succeeded')
            ->latest('finished_at')
            ->limit(10)
            ->get();

        return Inertia::render('admin/processing', [
            'summary' => [
                'pending_approval' => BookSubmission::query()->where('status', 'pending_approval')->count(),
                'queued' => (int) ($runCounts['queued'] ?? 0),
                'running' => (int) ($runCounts['running'] ?? 0),
                'needs_review' => (int) ($runCounts['needs_review'] ?? 0),
                'failed' => (int) ($runCounts['failed'] ?? 0),
                'ready_for_enrichment' => BookAsset::query()
                    ->where('ingestion_status', 'ready_for_enrichment')
                    ->count(),
                'completed_last_day' => IngestionRun::query()
                    ->where('status', 'succeeded')
                    ->where('finished_at', '>=', now()->subDay())
                    ->count(),
            ],
            'queue' => [
                'depths' => [
                    'high' => (int) ($queueDepths['high'] ?? 0),
                    'normal' => (int) ($queueDepths['normal'] ?? 0),
                    'low' => (int) ($queueDepths['low'] ?? 0),
                ],
                'oldest_queued_at' => $oldestQueued?->queued_at?->toIso8601String(),
                'configured_concurrency' => (int) config('mnemosyne.ingestion.concurrency'),
            ],
            'stages' => $stageDistribution,
            'recent_failures' => $recentFailures->map(fn (IngestionRun $run) => [
                'public_id' => $run->public_id,
                'filename' => $run->submission?->original_filename,
                'status' => $run->status->value,
                'stage' => $run->current_stage?->value,
                'error_code' => $run->last_error_code,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ]),
            'recent_completions' => $recentCompletions->map(fn (IngestionRun $run) => [
                'public_id' => $run->public_id,
                'filename' => $run->submission?->original_filename,
                'duration_seconds' => $run->started_at !== null && $run->finished_at !== null
                    ? (int) abs($run->finished_at->diffInSeconds($run->started_at))
                    : null,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ]),
        ]);
    }

    public function runs(Request $request): Response
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: 'all',
            'stage' => $request->string('stage')->toString() ?: 'all',
            'priority' => $request->string('priority')->toString() ?: 'all',
            'q' => $request->string('q')->toString(),
        ];

        $runs = IngestionRun::query()
            ->with(['submission:id,public_id,original_filename,user_id,source_type', 'submission.user:id,name'])
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['stage'] !== 'all', fn ($query) => $query->where('current_stage', $filters['stage']))
            ->when($filters['priority'] !== 'all', fn ($query) => $query->where('priority', $filters['priority']))
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $query->whereHas('submission', fn ($submissions) => $submissions
                    ->where('original_filename', 'like', '%'.$filters['q'].'%'));
            })
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/processing/runs', [
            'filters' => $filters,
            'runs' => $runs->through(fn (IngestionRun $run) => [
                'public_id' => $run->public_id,
                'filename' => $run->submission?->original_filename,
                'submitter' => $run->submission?->user?->name,
                'source_type' => $run->submission?->source_type?->value,
                'status' => $run->status->value,
                'stage' => $run->current_stage?->value,
                'priority' => $run->priority->value,
                'progress' => $run->progress,
                'attempts' => null,
                'queued_at' => $run->queued_at?->toIso8601String(),
                'updated_at' => $run->updated_at->toIso8601String(),
            ]),
        ]);
    }

    public function show(IngestionRun $run): Response
    {
        $run->load([
            'submission.user:id,name,email',
            'asset.edition.work',
            'asset.edition.contributors',
            'asset.duplicateCandidates.duplicateOf:id,public_id,original_filename',
            'asset.duplicateOfCandidates.asset:id,public_id,original_filename',
            'attempts',
            'events' => fn ($query) => $query->latest('id')->limit(200),
        ]);

        $asset = $run->asset;
        $submission = $run->submission;

        $duplicates = collect()
            ->concat($asset?->duplicateCandidates ?? [])
            ->concat($asset?->duplicateOfCandidates ?? [])
            ->map(fn ($candidate) => [
                'public_id' => $candidate->public_id,
                'reason' => $candidate->reason,
                'status' => $candidate->status->value,
                'other_asset' => $candidate->book_asset_id === $asset?->id
                    ? ($candidate->duplicateOf?->only(['public_id', 'original_filename']))
                    : ($candidate->asset?->only(['public_id', 'original_filename'])),
                'evidence' => $candidate->evidence,
            ])
            ->values();

        return Inertia::render('admin/processing/show', [
            'run' => [
                'public_id' => $run->public_id,
                'status' => $run->status->value,
                'stage' => $run->current_stage?->value,
                'priority' => $run->priority->value,
                'progress' => $run->progress,
                'pipeline_version' => $run->pipeline_version,
                'cancel_requested' => $run->cancel_requested,
                'queued_at' => $run->queued_at?->toIso8601String(),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'heartbeat_at' => $run->heartbeat_at?->toIso8601String(),
                'error_code' => $run->last_error_code,
                'error_message' => $run->last_error_message,
                'review_issues' => $run->review_issues ?? [],
                'overridden_issues' => $run->overridden_issues ?? [],
                'correlation_id' => $run->correlation_id,
            ],
            'submission' => $submission === null ? null : [
                'public_id' => $submission->public_id,
                'original_filename' => $submission->original_filename,
                'source_type' => $submission->source_type->value,
                'submitter' => $submission->user?->only(['name', 'email']),
                'note' => $submission->note,
                'is_exact_duplicate' => $submission->is_exact_duplicate,
                'created_at' => $submission->created_at->toIso8601String(),
            ],
            'asset' => $asset === null ? null : [
                'public_id' => $asset->public_id,
                'sha256' => $asset->sha256,
                'content_sha256' => $asset->content_sha256,
                'epub_version' => $asset->epub_version,
                'size_bytes' => $asset->size_bytes,
                'ingestion_status' => $asset->ingestion_status->value,
                'validation_status' => $asset->validation_status,
                'metadata' => $asset->extracted_metadata,
                'structure_summary' => $asset->structure_summary,
                'reconciliation' => $asset->reconciliation,
                'edition' => $asset->edition === null ? null : [
                    'public_id' => $asset->edition->public_id,
                    'title' => $asset->edition->title,
                    'language' => $asset->edition->language,
                    'publisher' => $asset->edition->publisher,
                    'work' => [
                        'public_id' => $asset->edition->work->public_id,
                        'title' => $asset->edition->work->canonical_title,
                        'status' => $asset->edition->work->status->value,
                    ],
                    'contributors' => $asset->edition->contributors->map(fn ($contributor) => [
                        'name' => $contributor->pivot->credited_as,
                        'role' => $contributor->pivot->role,
                    ])->all(),
                ],
            ],
            'attempts' => $run->attempts->map(fn ($attempt) => [
                'stage' => $attempt->stage->value,
                'attempt' => $attempt->attempt,
                'status' => $attempt->status,
                'handler_version' => $attempt->handler_version,
                'duration_ms' => $attempt->duration_ms,
                'error_code' => $attempt->error_code,
                'error_message' => $attempt->error_message,
                'result_summary' => $attempt->result_summary,
                'started_at' => $attempt->started_at?->toIso8601String(),
            ]),
            'events' => $run->events->map(fn ($event) => [
                'type' => $event->type,
                'payload' => $event->payload,
                'actor' => $event->actor?->name,
                'created_at' => $event->created_at->toIso8601String(),
            ]),
            'duplicates' => $duplicates,
        ]);
    }

    public function retry(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): RedirectResponse
    {
        $orchestrator->retry($run, $request->user());

        return back()->with('success', 'Retry queued from stage '.($run->current_stage?->value ?? 'hash').'.');
    }

    public function cancel(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): RedirectResponse
    {
        $orchestrator->requestCancel($run, $request->user());

        return back()->with('success', 'Cancellation requested.');
    }

    public function priority(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): RedirectResponse
    {
        $validated = $request->validate([
            'priority' => ['required', 'in:high,normal,low'],
        ]);

        $orchestrator->changePriority($run, IngestionPriority::from($validated['priority']), $request->user());

        return back()->with('success', 'Priority updated.');
    }

    public function override(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $orchestrator->overrideIssue($run, $validated['code'], $request->user());

        return back()->with('success', 'Issue overridden; run resumed.');
    }
}
