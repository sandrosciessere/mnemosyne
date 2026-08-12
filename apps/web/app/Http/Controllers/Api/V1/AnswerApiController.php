<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Library\InvalidTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\GroundedAnswerRun;
use App\Services\Answers\AnswerPresenter;
use App\Services\Answers\AnswerScopeResolver;
use App\Services\Answers\AnswerSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grounded answers API. POST is asynchronous (202 + polling): the
 * pipeline takes minutes on the local CPU provider, and progress is
 * real persisted job state, never a client-side timer.
 */
class AnswerApiController extends Controller
{
    public function store(
        Request $request,
        AnswerScopeResolver $scopes,
        AnswerSubmissionService $submissions,
    ): JsonResponse {
        $config = config('mnemosyne.answers');

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:'.$config['question_min_chars'], 'max:'.$config['question_max_chars']],
            'scope' => ['sometimes', 'array'],
            'scope.book_asset_ids' => ['sometimes', 'array', 'max:'.$config['scope_max_assets']],
            'scope.book_asset_ids.*' => ['string', 'regex:/^[0-9a-z]{26}$/'],
            'conversation_id' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-z]{26}$/'],
        ]);

        $question = trim($validated['question']);

        if (mb_strlen($question) < (int) $config['question_min_chars']) {
            return $this->errorResponse('QUESTION_EMPTY', 'Question must not be blank.', 422);
        }

        $user = $request->user();
        $requestedIds = $validated['scope']['book_asset_ids'] ?? null;
        $assetIds = $scopes->resolve($user, $requestedIds);

        if ($assetIds === null) {
            return $this->errorResponse('SCOPE_NOT_ACCESSIBLE', 'One or more requested books are not accessible.', 403);
        }

        if ($assetIds === []) {
            return $this->errorResponse('SCOPE_EMPTY', 'No accessible books in scope.', 422);
        }

        $conversation = null;

        if (! empty($validated['conversation_id'])) {
            $conversation = Conversation::query()
                ->where('public_id', $validated['conversation_id'])
                ->first();

            // Fail closed and indistinguishably for unknown or foreign
            // conversations.
            if ($conversation === null || ($conversation->user_id !== $user->id && ! $user->isAdmin())) {
                return $this->errorResponse('CONVERSATION_NOT_ACCESSIBLE', 'Conversation not accessible.', 403);
            }
        }

        try {
            $run = $submissions->submit($user, $question, $assetIds, $requestedIds ?? [], $conversation);
        } catch (InvalidTransitionException $exception) {
            $status = $exception->errorCode === 'TOO_MANY_ACTIVE_ANSWERS' ? 429 : 409;

            return $this->errorResponse($exception->errorCode, $exception->getMessage(), $status);
        }

        return response()->json([
            'data' => [
                'id' => $run->public_id,
                'status' => $run->status->value,
                'conversation_id' => $run->conversation?->public_id,
                'url' => route('api.v1.answers.show', $run->public_id),
            ],
        ], 202);
    }

    public function show(Request $request, GroundedAnswerRun $answer, AnswerPresenter $presenter): JsonResponse
    {
        $user = $request->user();

        if ($answer->user_id !== $user->id && ! $user->isAdmin()) {
            return $this->errorResponse('ANSWER_NOT_ACCESSIBLE', 'Answer not accessible.', 403);
        }

        $includeDiagnostics = $user->isAdmin() && $request->boolean('debug');

        return response()->json(['data' => $presenter->present($answer, $includeDiagnostics)]);
    }

    public function evidence(Request $request, GroundedAnswerRun $answer, string $evidenceKey, AnswerPresenter $presenter): JsonResponse
    {
        $user = $request->user();

        if ($answer->user_id !== $user->id && ! $user->isAdmin()) {
            return $this->errorResponse('ANSWER_NOT_ACCESSIBLE', 'Answer not accessible.', 403);
        }

        $evidence = $answer->evidence()->where('evidence_key', $evidenceKey)->first();

        if ($evidence === null) {
            return $this->errorResponse('EVIDENCE_NOT_FOUND', 'Unknown evidence for this answer.', 404);
        }

        return response()->json(['data' => $presenter->presentEvidence($evidence)]);
    }

    private function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message, 'details' => (object) []],
        ], $status);
    }
}
