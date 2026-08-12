<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Trigram indexing backs scalable exact/literal retrieval
     * (LIKE/ILIKE over chunk text). PostgreSQL-only, additive, idempotent
     * — mirrors the existing pgvector extension migration pattern.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: other objects may depend on the extension.
    }
};
