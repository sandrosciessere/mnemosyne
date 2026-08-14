<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Second corrective pass (additive only): task contracts, claim
     * relevance and coverage-based outcome semantics. TaskContract data
     * itself lives inside the runs' `subquestions` JSON (auditable per
     * subquestion); these columns carry the component versions and the
     * per-claim relevance verdict.
     */
    public function up(): void
    {
        Schema::table('grounded_answer_runs', function (Blueprint $table) {
            $table->string('task_contract_version', 32)->nullable();
            $table->string('claim_relevance_gate_version', 40)->nullable();
            $table->string('coverage_evaluator_version', 40)->nullable();
        });

        Schema::table('grounded_answer_claims', function (Blueprint $table) {
            // passed | rejected (null for claims persisted before this pass
            // or rejected earlier in the chain)
            $table->string('relevance_result', 16)->nullable();
            $table->string('relevance_reason_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('grounded_answer_claims', function (Blueprint $table) {
            $table->dropColumn(['relevance_result', 'relevance_reason_code']);
        });

        Schema::table('grounded_answer_runs', function (Blueprint $table) {
            $table->dropColumn(['task_contract_version', 'claim_relevance_gate_version', 'coverage_evaluator_version']);
        });
    }
};
