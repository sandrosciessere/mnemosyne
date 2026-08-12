<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalized claim ↔ evidence relation: a claim may cite several
     * EvidenceUnits, one EvidenceUnit may support several claims (and
     * keeps one citation number across all of them).
     */
    public function up(): void
    {
        Schema::create('grounded_answer_claim_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grounded_answer_claim_id')
                ->constrained('grounded_answer_claims')->cascadeOnDelete();
            $table->foreignId('grounded_answer_evidence_id')
                ->constrained('grounded_answer_evidence')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['grounded_answer_claim_id', 'grounded_answer_evidence_id'],
                'claim_evidence_unique',
            );
            $table->index('grounded_answer_evidence_id', 'claim_evidence_evidence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grounded_answer_claim_evidence');
    }
};
