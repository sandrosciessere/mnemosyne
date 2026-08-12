<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable snapshot of every EvidenceUnit in an answer's
     * EvidencePacket. Citations resolve THROUGH THIS TABLE to canonical
     * source coordinates — never through the retrieval generation that
     * produced them (generations are superseded; answers are forever).
     *
     * The excerpt is the exact bounded canonical slice (unit text,
     * <= the configured unit maximum) — coordinates + hashes + bounded
     * excerpt, not wholesale source duplication.
     */
    public function up(): void
    {
        Schema::create('grounded_answer_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grounded_answer_run_id')
                ->constrained('grounded_answer_runs')->cascadeOnDelete();
            // Opaque model-facing key: E1, E2, ... (per run).
            $table->string('evidence_key', 8);
            $table->unsignedSmallInteger('ordinal');
            // Server-assigned citation number ([1], [2], ...) — only for
            // evidence actually cited by verified claims; null otherwise.
            $table->unsignedSmallInteger('citation_number')->nullable();
            $table->foreignId('book_asset_id')->nullable()
                ->constrained('book_assets')->nullOnDelete();
            // Denormalized identity snapshot so the audit trail survives
            // library mutations.
            $table->string('book_title', 500)->nullable();
            $table->string('work_title', 500)->nullable();
            $table->string('edition_label', 500)->nullable();
            $table->string('source_node_id', 64)->nullable();
            $table->unsignedInteger('spine_index');
            $table->string('source_href', 1024)->nullable();
            $table->string('source_fragment', 255)->nullable();
            $table->string('node_type', 32)->nullable();
            $table->json('heading_path')->nullable();
            // epub_cfi is nullable and NEVER invented: M1 artifacts do not
            // currently emit CFI; canonical coordinates are authoritative.
            $table->string('epub_cfi', 512)->nullable();
            $table->unsignedInteger('canonical_start');
            $table->unsignedInteger('canonical_end');
            $table->unsignedInteger('utf16_start');
            $table->unsignedInteger('utf16_end');
            $table->char('source_hash', 64)->nullable();
            $table->char('source_content_sha256', 64);
            $table->char('text_hash', 64);
            $table->text('excerpt');
            $table->json('retrieval_meta')->nullable();
            $table->string('unitizer_version', 32);
            $table->timestamps();

            $table->unique(['grounded_answer_run_id', 'evidence_key'], 'answer_evidence_key_unique');
            $table->index(['grounded_answer_run_id', 'citation_number']);
            $table->index('book_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grounded_answer_evidence');
    }
};
