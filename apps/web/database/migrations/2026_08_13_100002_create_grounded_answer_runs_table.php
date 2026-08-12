<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per grounded answer pipeline execution. Carries every
     * version/identity needed to audit an answer WITHOUT the active
     * retrieval generation: the run references the generation it used as
     * historical metadata, while evidence resolution goes through the
     * durable grounded_answer_evidence snapshot (canonical coordinates),
     * never back through retrieval chunks.
     */
    public function up(): void
    {
        Schema::create('grounded_answer_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()
                ->constrained('conversations')->nullOnDelete();
            $table->foreignId('user_message_id')->nullable();
            $table->text('question');
            // queued | retrieving | expanding_retrieval | generating |
            // verifying | ready | insufficient | failed
            $table->string('status', 32)->default('queued');
            // answered | partially_answered | insufficient_evidence (terminal only)
            $table->string('outcome', 32)->nullable();
            $table->string('classified_intent', 40)->nullable();
            $table->string('query_classifier_version', 32)->nullable();
            // Notice for intents beyond honest M3 capability (global
            // summary / longitudinal / tricky inference).
            $table->string('capability_notice', 64)->nullable();
            $table->foreignId('retrieval_generation_id')->nullable()
                ->constrained('retrieval_generations')->restrictOnDelete();
            $table->string('retrieval_profile_version', 32)->nullable();
            $table->json('retrieval_diagnostics')->nullable();
            $table->unsignedTinyInteger('retrieval_expansion_count')->default(0);
            $table->string('evidence_unitizer_version', 32)->nullable();
            $table->json('evidence_stats')->nullable();
            $table->string('generator_prompt_version', 32)->nullable();
            $table->string('generator_provider', 64)->nullable();
            $table->string('generator_model', 128)->nullable();
            $table->string('generator_revision', 128)->nullable();
            $table->string('verifier_prompt_version', 32)->nullable();
            $table->string('verifier_provider', 64)->nullable();
            $table->string('verifier_model', 128)->nullable();
            $table->string('verifier_revision', 128)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 1024)->nullable();
            $table->json('timings_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['conversation_id']);
            $table->index(['status']);
            $table->index(['retrieval_generation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grounded_answer_runs');
    }
};
