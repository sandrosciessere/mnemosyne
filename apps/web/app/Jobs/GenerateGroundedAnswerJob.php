<?php

namespace App\Jobs;

use App\Enums\AnswerRunStatus;
use App\Models\GroundedAnswerRun;
use App\Services\Answers\GroundedAnswerOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes one grounded answer pipeline on the dedicated `answers`
 * queue. Transport-level tries stay at 1 — domain-level retries and
 * failure classification live in the orchestrator/providers — and a
 * hard worker death reconciles the run to a terminal failed state so
 * no answer is ever stuck in generating/verifying forever.
 */
class GenerateGroundedAnswerJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = (int) config('mnemosyne.answers.job_timeout_seconds');
    }

    public function uniqueId(): string
    {
        return 'answer-run:'.$this->runId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 300;
    }

    public function handle(GroundedAnswerOrchestrator $orchestrator): void
    {
        $run = GroundedAnswerRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $orchestrator->execute($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = GroundedAnswerRun::query()->find($this->runId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $run->forceFill([
            'status' => AnswerRunStatus::Failed,
            'error_code' => 'ANSWER_JOB_FAILED',
            'error_message' => mb_substr(
                'The answer job terminated unexpectedly.'
                .($exception !== null ? ' '.$exception->getMessage() : ''),
                0,
                1024,
            ),
            'completed_at' => now(),
        ])->save();
    }
}
