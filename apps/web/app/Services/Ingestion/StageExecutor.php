<?php

namespace App\Services\Ingestion;

use App\Enums\IngestionEventType;
use App\Enums\IngestionRunStatus;
use App\Enums\IngestionStage;
use App\Exceptions\Library\StorageException;
use App\Exceptions\Library\WorkerUnavailableException;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Models\IngestionStageAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a single stage attempt for a run: locking, cancellation checks,
 * attempt bookkeeping, verdict classification, retry/backoff and next
 * stage dispatch. Content transformation itself lives in the Python
 * worker (validate/parse/normalize/structure) or in HashStage (hash).
 */
class StageExecutor
{
    /** Stage handler versions shown in the control plane. The worker owns
     *  the authoritative values for its stages; these mirror them so admin
     *  UI and run records agree on what produced each artifact. */
    public const HANDLER_VERSIONS = [
        'hash' => '1.0.0',
        'validate' => '1.0.0',
        'parse' => '1.0.0',
        'normalize' => '1.0.0',
        'structure' => '1.0.0',
    ];

    public function __construct(
        private readonly RunStateMachine $stateMachine,
        private readonly IngestionOrchestrator $orchestrator,
        private readonly HashStage $hashStage,
        private readonly WorkerStage $workerStage,
        private readonly ReconciliationService $reconciliation,
    ) {}

    public function execute(IngestionRun $run, IngestionStage $stage): void
    {
        $lock = Cache::store(config('mnemosyne.ingestion.lock_store'))
            ->lock('ingestion:run:'.$run->id, 590);

        if (! $lock->get()) {
            // Another worker is on this run (should not happen with one
            // job per run — defensive). Requeue instead of racing.
            Log::warning('ingestion.lock_busy', ['run' => $run->public_id, 'stage' => $stage->value]);
            $this->orchestrator->dispatchStage($run, $stage, delaySeconds: 30);

            return;
        }

        try {
            $this->executeLocked($run->refresh(), $stage);
        } finally {
            $lock->release();
        }
    }

    private function executeLocked(IngestionRun $run, IngestionStage $stage): void
    {
        if (! in_array($run->status, [IngestionRunStatus::Queued, IngestionRunStatus::Running], true)) {
            return; // Cancelled/failed/reviewed elsewhere meanwhile.
        }

        if ($run->cancel_requested) {
            $this->stateMachine->markCancelled($run);

            return;
        }

        $this->stateMachine->markRunning($run, $stage);

        $attemptNumber = $run->nextAttemptNumber($stage);
        $attempt = $this->openAttempt($run, $stage, $attemptNumber);

        IngestionEvent::record(IngestionEventType::StageStarted, run: $run, payload: [
            'stage' => $stage->value,
            'attempt' => $attemptNumber,
        ]);

        $startedAt = hrtime(true);

        try {
            $result = $this->runHandler($run, $stage);
        } catch (WorkerUnavailableException|StorageException $exception) {
            $this->handleRetryableFailure($run, $stage, $attempt, $exception, $startedAt);

            return;
        } catch (Throwable $exception) {
            Log::error('ingestion.stage_crashed', [
                'run' => $run->public_id,
                'correlation_id' => $run->correlation_id,
                'stage' => $stage->value,
                'exception' => $exception,
            ]);
            $this->closeAttempt($attempt, 'failed', $startedAt, errorCode: 'STAGE_CRASH', errorMessage: $exception->getMessage());
            $this->stateMachine->markFailed($run, 'STAGE_CRASH', 'Unexpected error while running stage '.$stage->value.'.');

            return;
        }

        $result = $this->applyOverrides($run, $result);

        if ($result->isSuccess()) {
            $this->completeStage($run, $stage, $attempt, $result, $startedAt);

            return;
        }

        if ($result->status === 'needs_review') {
            $this->closeAttempt($attempt, 'needs_review', $startedAt, summary: $result->summary, handlerVersion: $result->handlerVersion);
            $reviewable = array_merge(
                $result->issuesBySeverity('reviewable'),
                $result->issuesBySeverity('hard_block'),
            );
            $this->stateMachine->markNeedsReview($run, $stage, $reviewable);

            return;
        }

        // failed verdict from the handler (EPUB's fault, not infrastructure)
        $this->closeAttempt(
            $attempt,
            'failed',
            $startedAt,
            summary: $result->summary,
            handlerVersion: $result->handlerVersion,
            errorCode: $result->errorCode ?? 'STAGE_FAILED',
            errorMessage: $result->errorMessage ?? 'Stage reported failure.',
        );

        if ($result->retryable) {
            $this->scheduleRetryOrFail($run, $stage, $result->errorCode ?? 'STAGE_FAILED', $result->errorMessage ?? '', $attempt->attempt);

            return;
        }

        $this->stateMachine->markFailed(
            $run,
            $result->errorCode ?? 'STAGE_FAILED',
            $result->errorMessage ?? 'Stage '.$stage->value.' failed.',
        );
    }

    private function runHandler(IngestionRun $run, IngestionStage $stage): StageResult
    {
        return match ($stage) {
            IngestionStage::Hash => $this->hashStage->run($run),
            default => $this->workerStage->run($run, $stage),
        };
    }

    private function completeStage(
        IngestionRun $run,
        IngestionStage $stage,
        IngestionStageAttempt $attempt,
        StageResult $result,
        int $startedAt,
    ): void {
        // Side effects run after classification so that admin-overridden
        // verdicts still promote/persist correctly. All are idempotent.
        $this->workerStage->applySideEffects($run, $stage, $result);

        if ($stage === IngestionStage::Structure) {
            // Conservative Work/Edition linking + content-duplicate
            // candidates. Laravel domain logic — never the worker's call.
            $this->reconciliation->reconcile($run);
        }

        DB::transaction(function () use ($run, $stage, $attempt, $result, $startedAt) {
            $this->closeAttempt($attempt, 'succeeded', $startedAt, summary: $result->summary, handlerVersion: $result->handlerVersion);

            IngestionEvent::record(IngestionEventType::StageCompleted, run: $run, payload: [
                'stage' => $stage->value,
                'attempt' => $attempt->attempt,
                'duration_ms' => $attempt->duration_ms,
                'summary' => $this->compactSummary($result->summary),
            ]);

            foreach ($result->issuesBySeverity('warning') as $warning) {
                IngestionEvent::record(IngestionEventType::StageWarning, run: $run, payload: [
                    'stage' => $stage->value,
                    'code' => $warning['code'],
                    'message' => $warning['message'],
                ]);
            }

            $run->forceFill([
                'progress' => $stage->progressAfter(),
                'heartbeat_at' => now(),
            ])->save();
        });

        // Duplicate short-circuit: the hash stage may finish the whole run.
        if (($result->summary['duplicate_of_ready_asset'] ?? false) === true) {
            $this->stateMachine->markSucceeded($run->refresh());

            return;
        }

        $run = $run->refresh();

        if ($run->cancel_requested) {
            $this->stateMachine->markCancelled($run);

            return;
        }

        $next = $stage->next();

        if ($next === null) {
            $this->stateMachine->markSucceeded($run);

            return;
        }

        DB::transaction(function () use ($run, $next) {
            $run->forceFill(['current_stage' => $next])->save();
            $this->orchestrator->dispatchStage($run, $next);
        });
    }

    /**
     * Reviewable issues an admin explicitly overrode are removed before
     * classification. Hard blocks are never removable (defense in depth:
     * overrideIssue() refuses to store them in the first place).
     */
    private function applyOverrides(IngestionRun $run, StageResult $result): StageResult
    {
        $overridden = $run->overridden_issues ?? [];

        if ($overridden === [] || $result->status !== 'needs_review') {
            return $result;
        }

        $remaining = array_values(array_filter(
            $result->issues,
            function (array $issue) use ($overridden) {
                $isOverridden = in_array($issue['code'], $overridden, true)
                    && ($issue['overrideable'] ?? false)
                    && ($issue['severity'] ?? '') === 'reviewable';

                return ! $isOverridden;
            },
        ));

        $stillBlocking = array_filter(
            $remaining,
            fn ($issue) => in_array($issue['severity'] ?? '', ['reviewable', 'hard_block'], true),
        );

        if ($stillBlocking !== []) {
            return new StageResult('needs_review', $remaining, $result->summary, $result->handlerVersion);
        }

        return StageResult::passed($result->summary, $result->handlerVersion, $remaining);
    }

    private function handleRetryableFailure(
        IngestionRun $run,
        IngestionStage $stage,
        IngestionStageAttempt $attempt,
        Throwable $exception,
        int $startedAt,
    ): void {
        Log::warning('ingestion.stage_retryable_failure', [
            'run' => $run->public_id,
            'correlation_id' => $run->correlation_id,
            'stage' => $stage->value,
            'attempt' => $attempt->attempt,
            'error' => $exception->getMessage(),
        ]);

        $errorCode = $exception instanceof WorkerUnavailableException ? 'WORKER_UNAVAILABLE' : 'STORAGE_ERROR';

        $this->closeAttempt($attempt, 'failed', $startedAt, errorCode: $errorCode, errorMessage: $exception->getMessage());

        $this->scheduleRetryOrFail($run, $stage, $errorCode, $exception->getMessage(), $attempt->attempt);
    }

    private function scheduleRetryOrFail(
        IngestionRun $run,
        IngestionStage $stage,
        string $errorCode,
        string $errorMessage,
        int $attemptNumber,
    ): void {
        $maxAttempts = (int) config('mnemosyne.ingestion.retry.max_attempts_per_stage');

        if ($attemptNumber >= $maxAttempts) {
            $this->stateMachine->markFailed($run, $errorCode, $errorMessage);

            return;
        }

        $backoffs = config('mnemosyne.ingestion.retry.backoff_seconds', [30, 120, 600]);
        $delay = (int) ($backoffs[min($attemptNumber - 1, count($backoffs) - 1)] ?? 60);

        IngestionEvent::record(IngestionEventType::StageWarning, run: $run, payload: [
            'stage' => $stage->value,
            'code' => $errorCode,
            'message' => 'Transient failure, retry scheduled.',
            'retry_in_seconds' => $delay,
            'attempt' => $attemptNumber,
        ]);

        $this->stateMachine->heartbeat($run);
        $this->orchestrator->dispatchStage($run, $stage, delaySeconds: $delay);
    }

    private function openAttempt(IngestionRun $run, IngestionStage $stage, int $number): IngestionStageAttempt
    {
        $attempt = new IngestionStageAttempt;
        $attempt->forceFill([
            'ingestion_run_id' => $run->id,
            'stage' => $stage,
            'attempt' => $number,
            'status' => 'running',
            'handler_version' => self::HANDLER_VERSIONS[$stage->value] ?? null,
            'started_at' => now(),
            'worker_meta' => ['host' => gethostname() ?: null],
        ])->save();

        return $attempt;
    }

    private function closeAttempt(
        IngestionStageAttempt $attempt,
        string $status,
        int $startedAt,
        array $summary = [],
        ?string $handlerVersion = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $attempt->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => intdiv((int) (hrtime(true) - $startedAt), 1_000_000),
            'handler_version' => $handlerVersion ?? $attempt->handler_version,
            'result_summary' => $summary === [] ? null : $this->compactSummary($summary),
            'error_code' => $errorCode,
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 1000),
        ])->save();
    }

    /** Keep JSON summaries bounded — no giant nested worker payloads in rows. */
    private function compactSummary(array $summary): array
    {
        unset($summary['metadata'], $summary['spine'], $summary['toc']);

        return $summary;
    }
}
