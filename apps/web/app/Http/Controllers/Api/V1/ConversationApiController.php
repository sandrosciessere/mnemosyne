<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationMessageRole;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Answers\AnswerPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conversation listing/history. Assistant messages have no free-form
 * content: their payload IS the referenced grounded answer (verified
 * claims + citations).
 */
class ConversationApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = Conversation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $conversation) => [
                'id' => $conversation->public_id,
                'title' => $conversation->title,
                'last_activity_at' => $conversation->last_activity_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function show(Request $request, Conversation $conversation, AnswerPresenter $presenter): JsonResponse
    {
        $user = $request->user();

        if ($conversation->user_id !== $user->id && ! $user->isAdmin()) {
            return response()->json([
                'error' => ['code' => 'CONVERSATION_NOT_ACCESSIBLE', 'message' => 'Conversation not accessible.', 'details' => (object) []],
            ], 403);
        }

        $conversation->load(['messages.answerRun']);

        return response()->json([
            'data' => [
                'id' => $conversation->public_id,
                'title' => $conversation->title,
                'messages' => $conversation->messages->map(function ($message) use ($presenter) {
                    $base = [
                        'id' => $message->public_id,
                        'role' => $message->role->value,
                        'created_at' => $message->created_at?->toIso8601String(),
                    ];

                    if ($message->role === ConversationMessageRole::User) {
                        return $base + ['content' => $message->content];
                    }

                    return $base + [
                        'answer' => $message->answerRun !== null
                            ? $presenter->present($message->answerRun)
                            : null,
                    ];
                })->all(),
            ],
        ]);
    }
}
