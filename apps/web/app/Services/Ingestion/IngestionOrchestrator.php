<?php

namespace App\Services\Ingestion;

use App\Enums\IngestionEventType;
use App\Enums\IngestionPriority;
use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Exceptions\Library\InvalidTransitionException;
use App\Jobs\RunIngestionStageJob;
use App\Models\BookSubmission;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Laravel owns every domain state transition. The Python worker only
 * transforms content; it never decides what a run does next.
 */
class IngestionOrchestrator
{
    /**
     * Create a queued run for an approved submission and dispatch its
     * first stage after the surrounding transaction commits.
     */
    public function startRun(BookSubmission $submission): IngestionRun
    {
        $existingActive = $submission->runs()
            ->whereIn('status', array_map(fn ($case) => $case->value, IngestionRunStatus::activeCases()))
            ->exists();

        if ($existingActive) {
            throw new InvalidTransitionException(
                'RUN_ALREADY_ACTIVE',
                'This submission already has an active ingestion run.',
            );
        }

        $run = new IngestionRun;
        $run->forceFill([
            'book_submission_id' => $submission->id,
            'pipeline_version' => config('mnemosyne.ingestion.pipeline_version'),
            'status' => IngestionRunStatus::Queued,
            'priority' => $submission->priority,
            'progress' => 0,
            'queued_at' => now(),
            'heartbeat_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ])->save();

        IngestionEvent::record(IngestionEventType::RunQueued, $submission, $run, payload: [
            'pipeline_version' => $run->pipeline_version,
            'priority' => $run->priority->value,
        ]);

        $this->dispatchStage($run, IngestionStage::first());

        return $run;
    }

    /**
     * Queue the job for a stage on the run's current priority queue.
     * Wrapped in afterCommit so a rolled-back transition never leaks a job.
     */
    public function dispatchStage(IngestionRun $run, IngestionStage $stage, int $delaySeconds = 0): void
    {
        $job = (new RunIngestionStageJob($run->id, $stage->value))
            ->onConnection(config('mnemosyne.ingestion.queue_connection'))
            ->onQueue($run->priority->queue())
            ->afterCommit();

        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }

        dispatch($job);
    }

    /**
     * Admin retry of a failed or needs-review run: resumes from the stage
     * that stopped it, reusing all prior completed artifacts.
     */
    public function retry(IngestionRun $run, User $actor): void
    {
        DB::transaction(function () use ($run, $actor) {
            $run = IngestionRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! in_array($run->status, [IngestionRunStatus::Failed, IngestionRunStatus::NeedsReview], true)) {
                throw new InvalidTransitionException(
                    'RUN_NOT_RETRYABLE',
                    'Only failed or needs-review runs can be retried.',
                );
            }

            $stage = $run->current_stage ?? IngestionStage::first();

            $run->forceFill([
                'status' => IngestionRunStatus::Queued,
                'cancel_requested' => false,
                'last_error_code' => null,
                'last_error_message' => null,
                'heartbeat_at' => now(),
            ])->save();

            IngestionEvent::record(IngestionEventType::RetryRequested, run: $run, actor: $actor, payload: [
                'stage' => $stage->value,
            ]);

            $this->dispatchStage($run, $stage);
        });
    }

    /**
     * Cooperative cancellation: flags the run; stage boundaries honor the
     * flag. A queued run that has not started yet is cancelled immediately
     * by the stage job when it wakes up.
     */
    public function requestCancel(IngestionRun $run, ?User $actor = null): void
    {
        DB::transaction(function () use ($run, $actor) {
            $run = IngestionRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status->isTerminal()) {
                throw new InvalidTransitionException(
                    'RUN_ALREADY_FINISHED',
                    'This run has already finished.',
                );
            }

            $run->forceFill(['cancel_requested' => true])->save();

            IngestionEvent::record(IngestionEventType::CancelRequested, run: $run, actor: $actor);

            // A run stopped in needs_review has no queued job that could
            // observe the flag — finalize the cancellation right here.
            if ($run->status === IngestionRunStatus::NeedsReview) {
                app(RunStateMachine::class)->markCancelled($run);
            }
        });
    }

    public function changePriority(IngestionRun $run, IngestionPriority $priority, User $actor): void
    {
        DB::transaction(function () use ($run, $priority, $actor) {
            $run = IngestionRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status->isTerminal()) {
                throw new InvalidTransitionException(
                    'RUN_ALREADY_FINISHED',
                    'Priority can only be changed while a run is active.',
                );
            }

            $previous = $run->priority;
            $run->forceFill(['priority' => $priority])->save();
            $run->submission->forceFill(['priority' => $priority])->save();

            IngestionEvent::record(IngestionEventType::PriorityChanged, run: $run, actor: $actor, payload: [
                'from' => $previous->value,
                'to' => $priority->value,
            ]);
        });
        // Takes effect at the next stage boundary: already-queued jobs stay
        // on their queue, every subsequent stage is dispatched on the new one.
    }

    /**
     * Admin override of a reviewable, overrideable issue. Hard security
     * blocks are never overrideable (enforced by the worker marking them
     * overrideable=false and by the check below).
     */
    public function overrideIssue(IngestionRun $run, string $issueCode, User $actor): void
    {
        DB::transaction(function () use ($run, $issueCode, $actor) {
            $run = IngestionRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== IngestionRunStatus::NeedsReview) {
                throw new InvalidTransitionException(
                    'RUN_NOT_IN_REVIEW',
                    'Only needs-review runs accept issue overrides.',
                );
            }

            $issue = collect($run->review_issues ?? [])->firstWhere('code', $issueCode);

            if ($issue === null) {
                throw new InvalidTransitionException('ISSUE_NOT_FOUND', 'Unknown issue code for this run.');
            }

            if (! ($issue['overrideable'] ?? false)) {
                throw new InvalidTransitionException(
                    'ISSUE_NOT_OVERRIDEABLE',
                    'This issue is a hard block and cannot be overridden.',
                );
            }

            $overridden = collect($run->overridden_issues ?? [])->push($issueCode)->unique()->values()->all();
            $run->forceFill(['overridden_issues' => $overridden])->save();

            IngestionEvent::record(IngestionEventType::IssueOverridden, run: $run, actor: $actor, payload: [
                'code' => $issueCode,
            ]);
        });

        // Resume by retrying the blocked stage; the executor filters
        // overridden codes when classifying the worker verdict.
        $this->retry($run->refresh(), $actor);
    }
}
