<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\IngestionPriority;
use App\Http\Controllers\Controller;
use App\Http\Resources\IngestionRunResource;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use App\Models\SystemSetting;
use App\Services\Ingestion\IngestionOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AdminIngestionRunApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = IngestionRun::query()
            ->with(['submission:id,public_id,original_filename,source_type', 'asset:id,public_id,sha256,content_sha256,ingestion_status'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('stage'), fn ($query) => $query->where('current_stage', $request->string('stage')->toString()))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')->toString()))
            ->orderByDesc('id')
            ->cursorPaginate(max(1, min((int) $request->integer('per_page', 25), 100)));

        return IngestionRunResource::collection($runs);
    }

    public function show(IngestionRun $run): IngestionRunResource
    {
        return new IngestionRunResource($run->load([
            'submission',
            'asset',
            'attempts',
            'events' => fn ($query) => $query->latest('id')->limit(200),
        ]));
    }

    public function retry(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): IngestionRunResource
    {
        $orchestrator->retry($run, $request->user());

        return new IngestionRunResource($run->refresh());
    }

    public function cancel(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): IngestionRunResource
    {
        $orchestrator->requestCancel($run, $request->user());

        return new IngestionRunResource($run->refresh());
    }

    public function priority(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): IngestionRunResource
    {
        $validated = $request->validate([
            'priority' => ['required', 'in:high,normal,low'],
        ]);

        $orchestrator->changePriority($run, IngestionPriority::from($validated['priority']), $request->user());

        return new IngestionRunResource($run->refresh());
    }

    public function pause(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): IngestionRunResource
    {
        $orchestrator->pause($run, $request->user());

        return new IngestionRunResource($run->refresh());
    }

    public function resume(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): IngestionRunResource
    {
        $orchestrator->resume($run, $request->user());

        return new IngestionRunResource($run->refresh());
    }

    public function markUnsupported(Request $request, IngestionRun $run, IngestionOrchestrator $orchestrator): IngestionRunResource
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $orchestrator->markUnsupported($run, $request->user(), $validated['reason'] ?? null);

        return new IngestionRunResource($run->refresh());
    }

    public function pauseGlobal(Request $request, IngestionOrchestrator $orchestrator): JsonResponse
    {
        $orchestrator->pauseGlobally($request->user());

        return response()->json(['data' => ['ingestion_paused' => true]]);
    }

    public function resumeGlobal(Request $request, IngestionOrchestrator $orchestrator): JsonResponse
    {
        $orchestrator->resumeGlobally($request->user());

        return response()->json(['data' => ['ingestion_paused' => false]]);
    }

    /** Real aggregates backing the control plane — never fabricated. */
    public function overview(): JsonResponse
    {
        $runCounts = IngestionRun::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'pending_approval' => BookSubmission::query()->where('status', 'pending_approval')->count(),
                'runs' => [
                    'queued' => (int) ($runCounts['queued'] ?? 0),
                    'running' => (int) ($runCounts['running'] ?? 0),
                    'needs_review' => (int) ($runCounts['needs_review'] ?? 0),
                    'failed' => (int) ($runCounts['failed'] ?? 0),
                    'succeeded' => (int) ($runCounts['succeeded'] ?? 0),
                    'cancelled' => (int) ($runCounts['cancelled'] ?? 0),
                ],
                'queue_depths' => IngestionRun::query()
                    ->where('status', 'queued')
                    ->select('priority', DB::raw('count(*) as total'))
                    ->groupBy('priority')
                    ->pluck('total', 'priority'),
                'stage_distribution' => IngestionRun::query()
                    ->whereIn('status', ['queued', 'running'])
                    ->whereNotNull('current_stage')
                    ->select('current_stage', DB::raw('count(*) as total'))
                    ->groupBy('current_stage')
                    ->pluck('total', 'current_stage'),
                'oldest_queued_at' => IngestionRun::query()
                    ->where('status', 'queued')
                    ->min('queued_at'),
                'ready_for_enrichment' => BookAsset::query()
                    ->where('ingestion_status', 'ready_for_enrichment')
                    ->count(),
                'configured_concurrency' => (int) config('mnemosyne.ingestion.concurrency'),
                'pipeline_version' => (string) config('mnemosyne.ingestion.pipeline_version'),
                'ingestion_paused' => SystemSetting::ingestionPaused(),
                'observability' => $this->observabilityAggregates(),
            ],
        ]);
    }

    private function observabilityAggregates(): array
    {
        $dayAgo = now()->subDay();
        $staleThreshold = now()->subMinutes((int) config('mnemosyne.ingestion.stale_after_minutes'));

        $finishedLastDay = IngestionRun::query()
            ->whereIn('status', ['succeeded', 'failed'])
            ->where('finished_at', '>=', $dayAgo)
            ->count();
        $failedLastDay = IngestionRun::query()
            ->where('status', 'failed')
            ->where('finished_at', '>=', $dayAgo)
            ->count();

        return [
            'retries_last_day' => DB::table('ingestion_stage_attempts')
                ->where('attempt', '>', 1)
                ->where('started_at', '>=', $dayAgo)
                ->count(),
            'failed_last_day' => $failedLastDay,
            'error_rate_last_day' => $finishedLastDay > 0
                ? round($failedLastDay * 100 / $finishedLastDay, 1)
                : null,
            'completed_last_hour' => IngestionRun::query()
                ->where('status', 'succeeded')
                ->where('finished_at', '>=', now()->subHour())
                ->count(),
            'stale_running' => IngestionRun::query()
                ->where('status', 'running')
                ->where('heartbeat_at', '<', $staleThreshold)
                ->count(),
            'avg_stage_duration_ms' => DB::table('ingestion_stage_attempts')
                ->select('stage', DB::raw('avg(duration_ms) as avg_ms'))
                ->where('status', 'succeeded')
                ->where('finished_at', '>=', $dayAgo)
                ->groupBy('stage')
                ->pluck('avg_ms', 'stage')
                ->map(fn ($value) => (int) $value)
                ->all() ?: null,
        ];
    }
}
