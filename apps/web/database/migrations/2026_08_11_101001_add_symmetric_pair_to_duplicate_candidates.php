<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A duplicate candidate is a pair of assets and a reason — the pair is
 * unordered: (A,B) and (B,A) are the same signal. The original
 * directional unique (book_asset_id, duplicate_of_asset_id, reason) let a
 * reversed row slip through. Add an ordered canonical pair
 * (asset_low_id <= asset_high_id) with a DB-enforced symmetric unique.
 *
 * Additive: the directional columns are kept (other engineers' display
 * queries read them) and are always written in canonical order by the
 * reconciliation service, so both uniques agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duplicate_candidates', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_low_id')->nullable();
            $table->unsignedBigInteger('asset_high_id')->nullable();
        });

        // Backfill existing rows into canonical (low, high) order. CASE
        // expressions are portable across PostgreSQL and SQLite.
        DB::statement(
            'UPDATE duplicate_candidates SET '
            .'asset_low_id = CASE WHEN book_asset_id <= duplicate_of_asset_id '
            .'THEN book_asset_id ELSE duplicate_of_asset_id END, '
            .'asset_high_id = CASE WHEN book_asset_id <= duplicate_of_asset_id '
            .'THEN duplicate_of_asset_id ELSE book_asset_id END'
        );

        Schema::table('duplicate_candidates', function (Blueprint $table) {
            $table->unique(['asset_low_id', 'asset_high_id', 'reason'], 'duplicate_candidate_symmetric_unique');
        });
    }

    public function down(): void
    {
        Schema::table('duplicate_candidates', function (Blueprint $table) {
            $table->dropUnique('duplicate_candidate_symmetric_unique');
            $table->dropColumn(['asset_low_id', 'asset_high_id']);
        });
    }
};
