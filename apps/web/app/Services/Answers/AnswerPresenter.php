<?php

namespace App\Services\Answers;

use App\Enums\AnswerRunStatus;
use App\Enums\ClaimVerificationStatus;
use App\Models\GroundedAnswerEvidence;
use App\Models\GroundedAnswerRun;

/**
 * Canonical user-facing answer representation, shared by the REST API
 * and the Inertia UI. Verified claims only; epistemic labels are the
 * confidence representation (no scores, no percentages); citations are
 * server-assigned numbers resolving to durable evidence snapshots.
 */
class AnswerPresenter
{
    public function present(GroundedAnswerRun $run, bool $includeDiagnostics = false): array
    {
        $run->loadMissing(['claims.evidence', 'evidence', 'scopeAssets', 'conversation']);

        $citations = [];

        foreach ($run->evidence as $evidence) {
            if ($evidence->citation_number !== null) {
                $citations[] = $this->presentEvidence($evidence);
            }
        }

        usort($citations, fn ($a, $b) => $a['number'] <=> $b['number']);

        $claims = [];
        $rejectedCount = 0;

        foreach ($run->claims as $claim) {
            if ($claim->verification_status !== ClaimVerificationStatus::Verified) {
                $rejectedCount++;

                continue;
            }

            $numbers = $claim->evidence
                ->pluck('citation_number')
                ->filter(fn ($n) => $n !== null)
                ->sort()
                ->values()
                ->all();

            $claims[] = [
                'key' => $claim->claim_key,
                'text' => $claim->claim_text,
                'label' => $claim->final_label?->value,
                'label_user' => $claim->final_label?->userLabel(),
                'subquestion' => $claim->subquestion_key,
                'citations' => $numbers,
            ];
        }

        // Minimal verified CitationSpans per citation (union across the
        // claims citing each unit), for transparency + precise reader
        // highlighting.
        $spansByEvidenceId = [];

        foreach ($run->claims as $claim) {
            foreach ($claim->evidence as $evidence) {
                $atoms = json_decode((string) ($evidence->pivot->atoms ?? '[]'), true) ?: [];

                foreach ($atoms as $atom) {
                    $spansByEvidenceId[$evidence->id][$atom['key']] = [
                        'canonical_start' => $atom['canonical_start'],
                        'canonical_end' => $atom['canonical_end'],
                    ];
                }
            }
        }

        foreach ($citations as &$citation) {
            $evidenceId = $run->evidence->firstWhere('evidence_key', $citation['evidence_key'])?->id;
            $citation['spans'] = array_values($spansByEvidenceId[$evidenceId] ?? []);
        }
        unset($citation);

        $data = [
            'id' => $run->public_id,
            'status' => $run->status->value,
            'outcome' => $run->outcome?->value,
            'question' => $run->question,
            'conversation_id' => $run->conversation?->public_id,
            'intent' => $run->classified_intent?->value,
            'capability_notice' => $run->capability_notice,
            'retrieval_expanded' => $run->retrieval_expansion_count > 0,
            'response_language' => $run->response_language,
            'duration_ms' => isset($run->timings_ms['total']) && $run->status->isTerminal()
                ? (int) $run->timings_ms['total']
                : null,
            'subquestions' => $run->subquestions,
            'claims' => $claims,
            'rejected_claim_count' => $rejectedCount,
            'citations' => $citations,
            'scope' => $run->scopeAssets->map(fn ($asset) => [
                'book_asset_id' => $asset->public_id,
                'title' => $asset->edition?->title ?? $asset->original_filename,
            ])->values()->all(),
            'skipped_assets' => $run->retrieval_diagnostics['skipped_assets'] ?? [],
            'error_code' => $run->status === AnswerRunStatus::Failed ? $run->error_code : null,
            'created_at' => $run->created_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];

        if ($includeDiagnostics) {
            $data['diagnostics'] = [
                'classifier_version' => $run->query_classifier_version,
                'retrieval_profile_version' => $run->retrieval_profile_version,
                'unitizer_version' => $run->evidence_unitizer_version,
                'decomposer_version' => $run->question_decomposer_version,
                'claim_gate_version' => $run->claim_gate_version,
                'task_contract_version' => $run->task_contract_version,
                'claim_relevance_gate_version' => $run->claim_relevance_gate_version,
                'coverage_evaluator_version' => $run->coverage_evaluator_version,
                'generator' => [
                    'provider' => $run->generator_provider,
                    'model' => $run->generator_model,
                    'revision' => $run->generator_revision,
                    'prompt_version' => $run->generator_prompt_version,
                ],
                'verifier' => [
                    'provider' => $run->verifier_provider,
                    'model' => $run->verifier_model,
                    'revision' => $run->verifier_revision,
                    'prompt_version' => $run->verifier_prompt_version,
                ],
                'retrieval_generation' => $run->generation?->public_id,
                'evidence_stats' => $run->evidence_stats,
                'retrieval_diagnostics' => $run->retrieval_diagnostics,
                'timings_ms' => $run->timings_ms,
                'error_message' => $run->error_message,
            ];
        }

        return $data;
    }

    /**
     * One citation/evidence record. Staleness is validated fail-closed
     * against the CURRENT asset fingerprint: when the book was
     * re-ingested (or removed) since the answer, the exact location can
     * no longer be guaranteed and the UI must say so instead of
     * highlighting a possibly-wrong position.
     */
    public function presentEvidence(GroundedAnswerEvidence $evidence): array
    {
        $asset = $evidence->asset;
        $stale = $asset === null || $asset->content_sha256 !== $evidence->source_content_sha256;

        return [
            'number' => $evidence->citation_number,
            'evidence_key' => $evidence->evidence_key,
            'book_asset_id' => $asset?->public_id,
            'book_title' => $evidence->book_title,
            'work_title' => $evidence->work_title,
            'edition_label' => $evidence->edition_label,
            'heading_path' => $evidence->heading_path ?? [],
            'node_type' => $evidence->node_type,
            'spine_index' => $evidence->spine_index,
            'source_href' => $evidence->source_href,
            'source_fragment' => $evidence->source_fragment,
            'epub_cfi' => $evidence->epub_cfi,
            'canonical_start' => $evidence->canonical_start,
            'canonical_end' => $evidence->canonical_end,
            'excerpt' => $evidence->excerpt,
            'stale' => $stale,
            'stale_reason' => $stale ? ($asset === null ? 'ASSET_REMOVED' : 'CITATION_SOURCE_CHANGED') : null,
        ];
    }
}
