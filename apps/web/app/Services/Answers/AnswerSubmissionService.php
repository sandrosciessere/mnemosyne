<?php

namespace App\Services\Answers;

use App\Enums\AnswerRunStatus;
use App\Enums\ConversationMessageRole;
use App\Exceptions\Library\InvalidTransitionException;
use App\Jobs\GenerateGroundedAnswerJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\GroundedAnswerRun;
use App\Models\RetrievalGeneration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates the durable run + conversation records and enqueues the
 * pipeline job. The active retrieval generation is snapshotted HERE:
 * a generation flip mid-run never changes what an answer executes
 * against, and historical answers keep their generation as audit
 * metadata forever.
 */
class AnswerSubmissionService
{
    /**
     * @param  list<int>  $assetIds  ACL-resolved internal ids
     * @param  list<string>  $requestedScopePublicIds  what the user explicitly selected (empty = all accessible)
     */
    public function submit(
        User $user,
        string $question,
        array $assetIds,
        array $requestedScopePublicIds,
        ?Conversation $conversation,
    ): GroundedAnswerRun {
        $generation = RetrievalGeneration::active();

        if ($generation === null) {
            throw new InvalidTransitionException(
                'RETRIEVAL_INACTIVE',
                'No active retrieval generation is available to answer questions.',
            );
        }

        $active = GroundedAnswerRun::query()
            ->where('user_id', $user->id)
            ->whereIn('status', array_map(fn ($case) => $case->value, AnswerRunStatus::activeCases()))
            ->count();

        if ($active >= (int) config('mnemosyne.answers.max_active_runs_per_user')) {
            throw new InvalidTransitionException(
                'TOO_MANY_ACTIVE_ANSWERS',
                'Too many answers are already being generated; wait for one to finish.',
            );
        }

        $run = DB::transaction(function () use ($user, $question, $assetIds, $conversation, $generation) {
            if ($conversation === null) {
                $conversation = new Conversation;
                $conversation->forceFill([
                    'user_id' => $user->id,
                    'title' => mb_substr(trim($question), 0, 200),
                    'last_activity_at' => now(),
                ])->save();
            } else {
                $conversation->forceFill(['last_activity_at' => now()])->save();
            }

            $message = new ConversationMessage;
            $message->forceFill([
                'conversation_id' => $conversation->id,
                'role' => ConversationMessageRole::User,
                'content' => $question,
            ])->save();

            $run = new GroundedAnswerRun;
            $run->forceFill([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'user_message_id' => $message->id,
                'question' => $question,
                'status' => AnswerRunStatus::Queued,
                'retrieval_generation_id' => $generation->id,
            ])->save();

            $run->scopeAssets()->attach($assetIds);

            return $run;
        });

        GenerateGroundedAnswerJob::dispatch($run->id)
            ->onConnection(config('mnemosyne.ingestion.queue_connection'))
            ->onQueue(config('mnemosyne.answers.queue'));

        return $run;
    }
}
