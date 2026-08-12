<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A retrieval generation is an immutable-once-active profile of every
     * component that shapes derived retrieval data: chunker version +
     * config, lexical/query-normalization versions, embedding model
     * identity + dimensions + metric, fusion and reranker configuration.
     * Incompatible changes create a NEW generation (blue/green: B builds
     * while A serves; activation is a single status flip).
     */
    public function up(): void
    {
        Schema::create('retrieval_generations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // building | active | superseded | failed
            $table->string('status', 32)->default('building')->index();
            // Full component profile snapshot (see RetrievalGeneration::config docs).
            $table->jsonb('config');
            // Deterministic hash of the chunker-relevant config subset: a
            // chunk set is only valid for exactly this hash.
            $table->char('chunker_config_hash', 64);
            $table->string('chunker_version', 32);
            $table->string('embedding_model_key', 64);
            $table->unsignedSmallInteger('embedding_dimensions');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        // At most one active generation: all active rows share the same
        // status value, so a partial unique on (status) admits one row.
        // Works on both PostgreSQL and SQLite.
        DB::statement(
            'CREATE UNIQUE INDEX retrieval_generations_one_active '.
            "ON retrieval_generations (status) WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_generations');
    }
};
