<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable per-(generation, asset) retrieval-indexing lifecycle —
     * deliberately separate from the Milestone 1 ingestion state machine:
     * a retrieval failure never rewrites a successfully ingested asset.
     */
    public function up(): void
    {
        Schema::create('retrieval_asset_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrieval_generation_id')
                ->constrained('retrieval_generations')->cascadeOnDelete();
            $table->foreignId('book_asset_id')
                ->constrained('book_assets')->cascadeOnDelete();
            // pending | chunking | embedding | ready | failed
            $table->string('status', 32)->default('pending');
            // Source identity this index run is valid for: the asset's
            // content fingerprint + the ingestion pipeline version that
            // produced the artifacts being chunked.
            $table->char('source_content_sha256', 64);
            $table->string('source_pipeline_version', 16);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('embedded_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 1024)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['retrieval_generation_id', 'book_asset_id'], 'retrieval_state_unique');
            $table->index(['retrieval_generation_id', 'status']);
            $table->index(['book_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_asset_states');
    }
};
