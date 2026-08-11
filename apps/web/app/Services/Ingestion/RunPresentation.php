<?php

namespace App\Services\Ingestion;

use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Models\BookAsset;
use App\Models\IngestionRun;

/**
 * Derived, read-only presentation models for the admin/user UX. Nothing
 * here mutates domain state: warnings are aggregated from the append-only
 * event timeline and pipeline stages are derived from durable stage
 * attempts + run disposition — never inferred from "run succeeded".
 */
class RunPresentation
{
    /**
     * Aggregate the run's stage.warning events into unique admin-facing
     * issues: the same warning code propagated through several stages is
     * ONE issue (with the stage list and occurrence count), not four.
     * Transient-retry notices (operational noise) are excluded — they
     * remain visible in the raw event timeline.
     *
     * @return list<array{code: string, message: string, stages: list<string>, occurrences: int, details: array}>
     */
    public static function warningsSummary(IngestionRun $run): array
    {
        // Same authoritative predicate the state machine uses to decide
        // ready-with-warnings: transient worker-retry notices are excluded so
        // the summary can never contradict the asset's warning status.
        $events = $run->events()
            ->contentWarnings()
            ->orderBy('id')
            ->limit(500)
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload ?? [];

            $code = (string) ($payload['code'] ?? 'UNKNOWN');

            $grouped[$code] ??= [
                'code' => $code,
                'message' => (string) ($payload['message'] ?? ''),
                'stages' => [],
                'occurrences' => 0,
                'details' => [],
            ];

            $grouped[$code]['occurrences']++;

            $stage = $payload['stage'] ?? null;
            if ($stage !== null && ! in_array($stage, $grouped[$code]['stages'], true)) {
                $grouped[$code]['stages'][] = $stage;
            }

            foreach (($payload['details'] ?? []) as $key => $value) {
                $grouped[$code]['details'][$key] = self::mergeDetail(
                    $grouped[$code]['details'][$key] ?? null,
                    $value,
                );
            }
        }

        return array_values($grouped);
    }

    /**
     * Warnings behind an asset's ready_for_enrichment_with_warnings
     * status: those of the run that actually PRODUCED the current
     * artifacts. A later exact-duplicate run also "succeeded" but only
     * ran hash — its (empty) warnings must not mask the producer's.
     */
    public static function warningsForAsset(BookAsset $asset): array
    {
        $producer = $asset->runs()
            ->where('status', IngestionRunStatus::Succeeded)
            ->whereHas('attempts', function ($attempts) {
                $attempts->where('stage', 'structure')->where('status', 'succeeded');
            })
            ->latest('id')
            ->first();

        $run = $producer ?? $asset->runs()
            ->where('status', IngestionRunStatus::Succeeded)
            ->latest('id')
            ->first();

        return $run === null ? [] : self::warningsSummary($run);
    }

    /**
     * Truthful per-stage execution status, from durable facts only:
     *
     *  - a stage with attempts reports its latest attempt's outcome;
     *  - a stage with NO attempt on a succeeded exact-duplicate run was
     *    never executed: its result was REUSED from the existing asset;
     *  - a stage with no attempt on a terminal run was not executed;
     *  - on an active run, unattempted stages are simply pending.
     *
     * @return list<array{stage: string, execution_status: string, attempts: int, duration_ms: int|null}>
     */
    public static function pipelineStages(IngestionRun $run): array
    {
        $attemptsByStage = $run->attempts()
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($attempt) => $attempt->stage->value);

        $isExactDuplicate = (bool) $run->submission?->is_exact_duplicate;

        $stages = [];

        foreach (IngestionStage::ordered() as $stage) {
            $stageAttempts = $attemptsByStage->get($stage->value);

            if ($stageAttempts !== null && $stageAttempts->isNotEmpty()) {
                $latest = $stageAttempts->last();

                $stages[] = [
                    'stage' => $stage->value,
                    'execution_status' => match ($latest->status) {
                        'succeeded' => 'succeeded',
                        'failed' => 'failed',
                        'needs_review' => 'needs_review',
                        'cancelled' => 'cancelled',
                        default => 'running',
                    },
                    'attempts' => $stageAttempts->count(),
                    'duration_ms' => $latest->duration_ms,
                ];

                continue;
            }

            $stages[] = [
                'stage' => $stage->value,
                'execution_status' => match (true) {
                    $run->status === IngestionRunStatus::Succeeded && $isExactDuplicate => 'reused',
                    $run->status->isTerminal() => 'not_executed',
                    default => 'pending',
                },
                'attempts' => 0,
                'duration_ms' => null,
            ];
        }

        return $stages;
    }

    /**
     * Exact-duplicate disposition for the run page: which existing asset
     * was reused and what that means. Null for non-duplicate runs.
     *
     * @return array{reused_asset: array{public_id: string, ingestion_status: string, original_filename: string}, disposition: string|null}|null
     */
    public static function duplicateInfo(IngestionRun $run): ?array
    {
        $submission = $run->submission;

        if ($submission === null || ! $submission->is_exact_duplicate) {
            return null;
        }

        $asset = $run->asset ?? $submission->asset;

        if ($asset === null) {
            return null;
        }

        $hashAttempt = $run->attempts()
            ->where('stage', 'hash')
            ->latest('id')
            ->first();

        return [
            'reused_asset' => [
                'public_id' => $asset->public_id,
                'ingestion_status' => $asset->ingestion_status->value,
                'original_filename' => $asset->original_filename,
            ],
            'disposition' => $hashAttempt?->result_summary['duplicate_disposition'] ?? null,
        ];
    }

    /** Bounded merge of a warning detail value across repeated events. */
    private static function mergeDetail(mixed $existing, mixed $incoming): mixed
    {
        if (is_array($incoming)) {
            $merged = array_values(array_unique(array_merge(
                is_array($existing) ? $existing : [],
                array_map(fn ($item) => is_scalar($item) ? $item : json_encode($item), $incoming),
            )));

            return array_slice($merged, 0, 20);
        }

        return $existing ?? (is_string($incoming) ? mb_substr($incoming, 0, 500) : $incoming);
    }
}
