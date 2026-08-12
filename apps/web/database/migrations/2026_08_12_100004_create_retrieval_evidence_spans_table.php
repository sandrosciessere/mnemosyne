<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * EvidenceSpan: first-class provenance mapping a region of a
     * retrieval chunk back to exact source coordinates. Every persisted
     * span satisfies start < end in all three coordinate systems, and
     * chunk regions NOT covered by any span are synthetic separators —
     * never quotable as source evidence.
     */
    public function up(): void
    {
        Schema::create('retrieval_evidence_spans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrieval_chunk_id')
                ->constrained('retrieval_chunks')->cascadeOnDelete();
            // Denormalized for direct authorized lookup at scale.
            $table->foreignId('book_asset_id')
                ->constrained('book_assets')->cascadeOnDelete();
            $table->unsignedInteger('span_ordinal');
            $table->string('source_node_id', 32);
            $table->unsignedInteger('spine_index');
            $table->string('href', 1024);
            $table->string('fragment', 255)->nullable();
            $table->string('node_type', 32);
            $table->jsonb('heading_path')->nullable();
            // Canonical corpus offsets (Unicode codepoints, M1 semantics).
            $table->unsignedInteger('canonical_start');
            $table->unsignedInteger('canonical_end');
            // UTF-16 code units — for the future JavaScript reader.
            $table->unsignedInteger('utf16_start');
            $table->unsignedInteger('utf16_end');
            // Position of this source text inside chunk source_text.
            $table->unsignedInteger('chunk_start');
            $table->unsignedInteger('chunk_end');
            // M1 per-node source hash (stale-citation detection).
            $table->char('source_hash', 64);
            $table->timestamps();

            $table->unique(['retrieval_chunk_id', 'span_ordinal'], 'retrieval_span_ordinal_unique');
            $table->index(['book_asset_id', 'canonical_start']);
            $table->index(['source_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_evidence_spans');
    }
};
