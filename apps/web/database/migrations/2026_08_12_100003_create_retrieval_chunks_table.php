<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retrieval chunks: derived, generation-scoped retrieval units built
     * deterministically from the Milestone 1 spine artifacts. source_text
     * contains ONLY source-backed characters (mapped by evidence spans);
     * heading context lives in separate columns and never masquerades as
     * book text.
     */
    public function up(): void
    {
        Schema::create('retrieval_chunks', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('retrieval_generation_id')
                ->constrained('retrieval_generations')->cascadeOnDelete();
            $table->foreignId('book_asset_id')
                ->constrained('book_assets')->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->jsonb('heading_path')->nullable();
            // Flattened heading path used for weighted lexical indexing.
            $table->string('heading_text', 1024)->nullable();
            $table->unsignedInteger('spine_index');
            $table->text('source_text');
            $table->unsignedInteger('char_count');
            $table->unsignedInteger('token_estimate');
            // Deterministic content+provenance fingerprint (see Chunker).
            $table->char('content_sha256', 64);
            // Canonical-corpus coverage of the NON-overlap region.
            $table->unsignedInteger('canonical_start');
            $table->unsignedInteger('canonical_end');
            // Number of leading source characters repeated from the
            // previous chunk (provenance-aware overlap).
            $table->unsignedInteger('overlap_prefix_chars')->default(0);
            // Source identity the chunk was built from.
            $table->char('source_content_sha256', 64);
            $table->timestamps();

            $table->unique(['retrieval_generation_id', 'book_asset_id', 'ordinal'], 'retrieval_chunk_ordinal_unique');
            $table->index(['book_asset_id', 'retrieval_generation_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Weighted multilingual-agnostic lexical vector: heading terms
            // rank A, body terms rank B; 'simple' config = no language
            // stemming assumptions (Mnemosyne is multilingual).
            DB::statement(<<<'SQL'
                ALTER TABLE retrieval_chunks ADD COLUMN tsv tsvector
                GENERATED ALWAYS AS (
                    setweight(to_tsvector('simple', coalesce(heading_text, '')), 'A') ||
                    setweight(to_tsvector('simple', source_text), 'B')
                ) STORED
                SQL);
            DB::statement('CREATE INDEX retrieval_chunks_tsv_gin ON retrieval_chunks USING gin (tsv)');
            // Trigram index backs scalable literal (LIKE/ILIKE) retrieval.
            DB::statement('CREATE INDEX retrieval_chunks_trgm ON retrieval_chunks USING gin (source_text gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_chunks');
    }
};
