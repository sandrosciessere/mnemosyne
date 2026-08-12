<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verified claims are the atomic answer unit. The generator's label
     * is advisory; final_label is decided by the independent verifier
     * mapping. Rejected/unsupported claims are persisted for audit but
     * NEVER displayed as supported content.
     */
    public function up(): void
    {
        Schema::create('grounded_answer_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grounded_answer_run_id')
                ->constrained('grounded_answer_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordinal');
            $table->string('claim_key', 8);
            $table->text('claim_text');
            $table->string('generator_suggested_label', 32)->nullable();
            // textual_fact | strong_inference | interpretation | conflict
            $table->string('final_label', 32)->nullable();
            // verified | rejected
            $table->string('verification_status', 16);
            $table->string('verifier_support_level', 16)->nullable();
            $table->string('verifier_reason_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['grounded_answer_run_id', 'claim_key'], 'answer_claim_key_unique');
            $table->index(['grounded_answer_run_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grounded_answer_claims');
    }
};
