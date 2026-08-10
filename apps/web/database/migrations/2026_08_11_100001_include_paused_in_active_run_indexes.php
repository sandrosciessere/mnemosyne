<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A paused run still owns its submission/asset pipeline: the partial
     * unique "one active run" indexes must treat it as active.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS ingestion_runs_one_active_per_submission');
        DB::statement('DROP INDEX IF EXISTS ingestion_runs_one_active_per_asset');

        DB::statement(
            'CREATE UNIQUE INDEX ingestion_runs_one_active_per_submission '.
            'ON ingestion_runs (book_submission_id) '.
            "WHERE status IN ('queued', 'running', 'paused', 'needs_review')"
        );
        DB::statement(
            'CREATE UNIQUE INDEX ingestion_runs_one_active_per_asset '.
            'ON ingestion_runs (book_asset_id) '.
            "WHERE status IN ('queued', 'running', 'paused', 'needs_review') AND book_asset_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ingestion_runs_one_active_per_submission');
        DB::statement('DROP INDEX IF EXISTS ingestion_runs_one_active_per_asset');

        DB::statement(
            'CREATE UNIQUE INDEX ingestion_runs_one_active_per_submission '.
            'ON ingestion_runs (book_submission_id) '.
            "WHERE status IN ('queued', 'running', 'needs_review')"
        );
        DB::statement(
            'CREATE UNIQUE INDEX ingestion_runs_one_active_per_asset '.
            'ON ingestion_runs (book_asset_id) '.
            "WHERE status IN ('queued', 'running', 'needs_review') AND book_asset_id IS NOT NULL"
        );
    }
};
