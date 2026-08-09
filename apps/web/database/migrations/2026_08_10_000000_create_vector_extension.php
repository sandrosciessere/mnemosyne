<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ensure the pgvector extension is available. Idempotent, and a no-op
     * on non-PostgreSQL connections (the test suite runs on sqlite).
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }
    }

    public function down(): void
    {
        // The extension is left in place on purpose: dropping it would
        // destroy any vector columns created by later migrations.
    }
};
