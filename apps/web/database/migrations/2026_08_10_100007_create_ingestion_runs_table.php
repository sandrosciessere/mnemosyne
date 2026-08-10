<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('book_submission_id')->constrained()->cascadeOnDelete();
            // Assigned once the hash stage has identified/created the asset.
            // nullOnDelete (not cascade): several submissions' runs can share
            // one asset (exact-duplicate adoption), so deleting an asset must
            // never destroy the append-only ingestion history of unrelated
            // submissions — the attribution is nulled, the audit trail stays.
            $table->foreignId('book_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pipeline_version', 16);
            $table->string('status', 32)->default('queued');
            $table->string('current_stage', 32)->nullable();
            $table->string('priority', 16)->default('normal');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // Touched by every stage transition; drives stale-run detection.
            $table->timestamp('heartbeat_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 1024)->nullable();
            // Reviewable issues blocking the run: [{code, message, overrideable, stage, details}]
            $table->jsonb('review_issues')->nullable();
            // Issue codes an admin explicitly overrode (only overrideable ones).
            $table->jsonb('overridden_issues')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['status', 'heartbeat_at']);
            $table->index(['book_asset_id']);
            $table->index(['current_stage']);
            // Control plane: recent failures/completions filter by status and
            // order by finished_at at 100k+ scale.
            $table->index(['status', 'finished_at']);
        });

        // One pipeline at a time per submission (and per identified asset).
        // Partial unique indexes: supported by both PostgreSQL and SQLite.
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

    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};
