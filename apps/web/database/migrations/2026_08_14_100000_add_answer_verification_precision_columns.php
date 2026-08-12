<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verifier-precision corrective pass (additive only).
     *
     * - runs: bounded question decomposition audit, response language,
     *   claim-gate version.
     * - claims: deterministic claim type, subquestion assignment and the
     *   application ClaimEvidenceGate outcome (the model verifier is not
     *   trusted on its own).
     * - claim_evidence pivot: minimal verified CitationSpan atoms as a
     *   JSON list of exact coordinate ranges. The broader EvidenceUnit
     *   snapshot in grounded_answer_evidence is PRESERVED — historical
     *   audit keeps both context and minimal spans. (A JSON column on
     *   the pivot was chosen over a separate table because atoms are
     *   only ever read through their claim↔evidence relation.)
     */
    public function up(): void
    {
        Schema::table('grounded_answer_runs', function (Blueprint $table) {
            $table->json('subquestions')->nullable();
            $table->string('response_language', 8)->nullable();
            $table->string('question_decomposer_version', 32)->nullable();
            $table->string('claim_gate_version', 32)->nullable();
        });

        Schema::table('grounded_answer_claims', function (Blueprint $table) {
            $table->string('claim_type', 32)->nullable();
            $table->string('subquestion_key', 8)->nullable();
            // verifier verdict vs application gate: a claim may be
            // verifier_positive yet gate_rejected.
            $table->string('gate_result', 16)->nullable();
            $table->string('gate_reason_code', 64)->nullable();
        });

        Schema::table('grounded_answer_claim_evidence', function (Blueprint $table) {
            // [{key, canonical_start, canonical_end, utf16_start, utf16_end}]
            $table->json('atoms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('grounded_answer_claim_evidence', function (Blueprint $table) {
            $table->dropColumn('atoms');
        });

        Schema::table('grounded_answer_claims', function (Blueprint $table) {
            $table->dropColumn(['claim_type', 'subquestion_key', 'gate_result', 'gate_reason_code']);
        });

        Schema::table('grounded_answer_runs', function (Blueprint $table) {
            $table->dropColumn(['subquestions', 'response_language', 'question_decomposer_version', 'claim_gate_version']);
        });
    }
};
