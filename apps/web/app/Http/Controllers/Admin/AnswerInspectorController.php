<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroundedAnswerRun;
use App\Services\Answers\AnswerPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-only answer inspector: complete audit trail for any answer
 * (intent, policy, packet, generator claims, verifier decisions,
 * citations, provider identities, timings, failures). No secrets, no
 * chain-of-thought — none is ever requested or stored.
 */
class AnswerInspectorController extends Controller
{
    public function index(Request $request): Response
    {
        $runs = GroundedAnswerRun::query()
            ->with(['user:id,name,email', 'conversation:id,public_id'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('admin/answers/index', [
            'runs' => $runs->map(fn (GroundedAnswerRun $run) => [
                'id' => $run->public_id,
                'question' => mb_substr($run->question, 0, 160),
                'status' => $run->status->value,
                'outcome' => $run->outcome?->value,
                'intent' => $run->classified_intent?->value,
                'user' => ['name' => $run->user?->name, 'email' => $run->user?->email],
                'error_code' => $run->error_code,
                'created_at' => $run->created_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function show(Request $request, GroundedAnswerRun $answer, AnswerPresenter $presenter): Response
    {
        $answer->load(['claims.evidence', 'evidence', 'scopeAssets', 'user:id,name,email']);

        return Inertia::render('admin/answers/show', [
            'answer' => $presenter->present($answer, includeDiagnostics: true),
            'user' => ['name' => $answer->user?->name, 'email' => $answer->user?->email],
            // Full audit: every claim including rejected ones with the
            // verifier's verdict, and every packet unit including
            // uncited ones.
            'all_claims' => $answer->claims->map(fn ($claim) => [
                'key' => $claim->claim_key,
                'ordinal' => $claim->ordinal,
                'text' => $claim->claim_text,
                'claim_type' => $claim->claim_type,
                'subquestion_key' => $claim->subquestion_key,
                'generator_suggested_label' => $claim->generator_suggested_label,
                'final_label' => $claim->final_label?->value,
                'verification_status' => $claim->verification_status->value,
                'verifier_support_level' => $claim->verifier_support_level?->value,
                'verifier_reason_code' => $claim->verifier_reason_code,
                // verifier_positive + gate_rejected is the audit signal
                // for "the model certified it, the application refused".
                'gate_result' => $claim->gate_result,
                'gate_reason_code' => $claim->gate_reason_code,
                'relevance_result' => $claim->relevance_result,
                'relevance_reason_code' => $claim->relevance_reason_code,
                'evidence_keys' => $claim->evidence->pluck('evidence_key')->all(),
                'support_atoms' => $claim->evidence
                    ->flatMap(fn ($evidence) => array_column(
                        json_decode((string) ($evidence->pivot->atoms ?? '[]'), true) ?: [],
                        'key',
                    ))->values()->all(),
            ])->all(),
            'all_evidence' => $answer->evidence->map(fn ($evidence) => $presenter->presentEvidence($evidence) + [
                'retrieval_meta' => $evidence->retrieval_meta,
                'unitizer_version' => $evidence->unitizer_version,
            ])->all(),
        ]);
    }
}
