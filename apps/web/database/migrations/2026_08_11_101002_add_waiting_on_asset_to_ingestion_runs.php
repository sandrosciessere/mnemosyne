<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run created for an exact-duplicate submission whose asset is still
 * being processed by another run must not claim success prematurely. It
 * parks (queued) with `waiting_on_asset_id` set until the owning run
 * reaches a terminal outcome, then mirrors it (ready → succeeded + grant,
 * unsupported → skipped, failed → failed). A waiting run never owns the
 * asset (book_asset_id stays null) so it cannot violate the one-active-run
 * -per-asset partial unique index.
 *
 * Deliberately a plain nullable column + index, NOT a ->constrained()
 * foreign key: on SQLite adding a real FK to an existing table forces a
 * full table rebuild, and that rebuild rewrites the run's *partial* unique
 * indexes (one active run per submission/asset) as ordinary unique
 * constraints. The relation is enforced in the application; assets are
 * never deleted in this milestone, and a waiting run is always resolved to
 * a terminal state before its asset could ever go away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('waiting_on_asset_id')->nullable();
            $table->index('waiting_on_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->dropIndex(['waiting_on_asset_id']);
            $table->dropColumn('waiting_on_asset_id');
        });
    }
};
