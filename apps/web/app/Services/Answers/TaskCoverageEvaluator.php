<?php

namespace App\Services\Answers;

use App\Enums\AnswerOutcome;

/**
 * coverage-evaluator 1.0.0 — final outcome semantics based on MATERIAL
 * TASK COVERAGE, never on internal claim counts. A generator that
 * produced five claims of which four were rejected still yields a
 * fully ANSWERED outcome when the surviving claim satisfies the whole
 * question; conversely, surviving grounded claims that answer nothing
 * yield INSUFFICIENT.
 *
 * Per-subquestion diagnosis codes distinguish recall failures from
 * reasoning failures for the admin inspector.
 */
class TaskCoverageEvaluator
{
    public const VERSION = 'coverage-evaluator 1.0.0';

    public const DIAG_CAPABILITY = 'CAPABILITY_UNSUPPORTED';

    public const DIAG_NO_HITS = 'NO_RETRIEVAL_HITS';

    public const DIAG_HITS_NOT_RELEVANT = 'RETRIEVAL_HITS_NOT_RELEVANT';

    public const DIAG_NO_CLAIM = 'GENERATOR_DID_NOT_FORM_CLAIM';

    public const DIAG_VERIFIER_REJECTED = 'VERIFIER_REJECTED';

    public const DIAG_GATE_REJECTED = 'CLAIM_GATE_REJECTED';

    public const DIAG_RELEVANCE_REJECTED = 'RELEVANCE_GATE_REJECTED';

    public const DIAG_PROTOCOL_ERROR = 'VERIFIER_PROTOCOL_ERROR';

    /**
     * @param  list<array{key: string, text: string}>  $subquestions  material subquestions (1..4)
     * @param  array<string, TaskContract>  $contracts
     * @param  array<string, array{surviving: int, generated: int, verifier_rejected: int,
     *                             gate_rejected: int, relevance_rejected: int, protocol_errors: int,
     *                             packet_units: int}>  $perSubquestion  aggregated per-SQ claim stats
     * @return array{outcome: AnswerOutcome, subquestions: list<array>}
     */
    public function evaluate(array $subquestions, array $contracts, array $perSubquestion): array
    {
        $answered = 0;
        $unanswered = 0;
        $capabilityLimited = 0;
        $rows = [];

        foreach ($subquestions as $subquestion) {
            $key = $subquestion['key'];
            $contract = $contracts[$key] ?? null;
            $stats = $perSubquestion[$key] ?? [
                'surviving' => 0, 'generated' => 0, 'verifier_rejected' => 0,
                'gate_rejected' => 0, 'relevance_rejected' => 0, 'protocol_errors' => 0,
                'packet_units' => 0,
            ];

            if ($contract !== null && ! $contract->supportedInM3) {
                $capabilityLimited++;
                $rows[] = $this->row($subquestion, $contract, 'capability_limited', self::DIAG_CAPABILITY);

                continue;
            }

            if ($stats['surviving'] > 0) {
                $answered++;
                $rows[] = $this->row($subquestion, $contract, 'answered', null);

                continue;
            }

            $unanswered++;
            $rows[] = $this->row($subquestion, $contract, 'unanswered', $this->diagnose($stats));
        }

        $outcome = match (true) {
            $answered > 0 && ($unanswered + $capabilityLimited) === 0 => AnswerOutcome::Answered,
            $answered > 0 => AnswerOutcome::PartiallyAnswered,
            default => AnswerOutcome::InsufficientEvidence,
        };

        return ['outcome' => $outcome, 'subquestions' => $rows];
    }

    /** Ordered by pipeline position: the FIRST stage that lost the answer. */
    private function diagnose(array $stats): string
    {
        return match (true) {
            $stats['packet_units'] === 0 => self::DIAG_NO_HITS,
            $stats['generated'] === 0 => self::DIAG_NO_CLAIM,
            $stats['protocol_errors'] > 0 && $stats['protocol_errors'] === $stats['generated'] => self::DIAG_PROTOCOL_ERROR,
            $stats['relevance_rejected'] > 0 && $stats['verifier_rejected'] === 0 && $stats['gate_rejected'] === 0 => self::DIAG_RELEVANCE_REJECTED,
            $stats['gate_rejected'] > 0 && $stats['verifier_rejected'] === 0 => self::DIAG_GATE_REJECTED,
            $stats['verifier_rejected'] > 0 => self::DIAG_VERIFIER_REJECTED,
            default => self::DIAG_HITS_NOT_RELEVANT,
        };
    }

    private function row(array $subquestion, ?TaskContract $contract, string $status, ?string $diagnosis): array
    {
        return [
            'key' => $subquestion['key'],
            'text' => $subquestion['text'],
            'status' => $status,
            'diagnosis' => $diagnosis,
        ] + ($contract !== null ? ['contract' => $contract->toArray()] : []);
    }
}
