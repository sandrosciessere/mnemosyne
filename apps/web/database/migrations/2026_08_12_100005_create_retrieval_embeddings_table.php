<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One embedding per chunk per generation. The column is dimensionless
     * `vector`; each generation gets its OWN partial expression HNSW index
     * cast to its profile's dimensions (created by GenerationManager, not
     * here), so models with different dimensions can never share an ANN
     * index and generations stay isolated.
     */
    public function up(): void
    {
        Schema::create('retrieval_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrieval_chunk_id')
                ->constrained('retrieval_chunks')->cascadeOnDelete();
            // Denormalized for scope filtering + generation isolation.
            $table->foreignId('retrieval_generation_id')
                ->constrained('retrieval_generations')->cascadeOnDelete();
            $table->foreignId('book_asset_id')
                ->constrained('book_assets')->cascadeOnDelete();
            $table->string('model_key', 64);
            $table->unsignedSmallInteger('dims');
            // sha256 of the exact embedded text — vectors are never reused
            // by ordinal coincidence.
            $table->char('embedding_text_sha256', 64);
            $table->timestamps();

            $table->unique(['retrieval_chunk_id'], 'retrieval_embedding_chunk_unique');
            $table->index(['retrieval_generation_id', 'book_asset_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE retrieval_embeddings ADD COLUMN embedding vector NOT NULL');
        } else {
            // SQLite (fast host suite): opaque text; dense retrieval is
            // exercised only on real PostgreSQL.
            DB::statement('ALTER TABLE retrieval_embeddings ADD COLUMN embedding text');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_embeddings');
    }
};
