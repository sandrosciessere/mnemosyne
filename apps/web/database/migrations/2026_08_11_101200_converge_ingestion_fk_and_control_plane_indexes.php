<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive convergence migration. Earlier iterations of this branch made two
 * schema changes by editing already-executed create migrations, which meant
 * a database that had already run the originals would never receive them.
 * Those create migrations have been restored to their deployed baseline;
 * this migration applies the two changes additively so that BOTH paths
 * converge on the same final schema:
 *
 *   fresh DB      : create (baseline) + this migration = final schema
 *   deployed DB   : `php artisan migrate`  = this migration = final schema
 *
 * No migrate:fresh / destructive recreation is required.
 *
 * Change 1 — ingestion_runs.book_asset_id: ON DELETE CASCADE → SET NULL.
 *   Several submissions' runs can share one asset (exact-duplicate
 *   adoption). Deleting an asset must NOT cascade-delete the append-only
 *   ingestion runs / attempts / events of unrelated submissions. Applied on
 *   PostgreSQL (the production database and the only driver that enforces FK
 *   actions); skipped on SQLite, where re-pointing a foreign key forces a
 *   full table rebuild that would rewrite the run's *partial* unique indexes
 *   as ordinary uniques, and where FK actions are not enforced anyway.
 *
 * Change 2 — control-plane indexes for the processing dashboard aggregates
 *   (throughput / retry-rate / recent failures) over append-only tables that
 *   reach 500k+ rows. Portable and idempotent via CREATE INDEX IF NOT EXISTS
 *   (supported by both PostgreSQL and SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Idempotent FK swap: drop the cascade constraint if present,
            // then add the SET NULL one. Metadata-only; no row changes.
            DB::statement('ALTER TABLE ingestion_runs DROP CONSTRAINT IF EXISTS ingestion_runs_book_asset_id_foreign');
            DB::statement('ALTER TABLE ingestion_runs ADD CONSTRAINT ingestion_runs_book_asset_id_foreign FOREIGN KEY (book_asset_id) REFERENCES book_assets (id) ON DELETE SET NULL');
        }

        DB::statement('CREATE INDEX IF NOT EXISTS ingestion_runs_status_finished_at_index ON ingestion_runs (status, finished_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS isa_status_finished_stage_index ON ingestion_stage_attempts (status, finished_at, stage)');
        DB::statement('CREATE INDEX IF NOT EXISTS isa_attempt_started_index ON ingestion_stage_attempts (attempt, started_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ingestion_runs_status_finished_at_index');
        DB::statement('DROP INDEX IF EXISTS isa_status_finished_stage_index');
        DB::statement('DROP INDEX IF EXISTS isa_attempt_started_index');

        // The book_asset_id FK is intentionally left as ON DELETE SET NULL on
        // rollback: reverting to ON DELETE CASCADE would reintroduce the
        // audit-destroying behaviour this migration exists to remove. The
        // safer action is the correct steady state, so rollback keeps it.
    }
};
