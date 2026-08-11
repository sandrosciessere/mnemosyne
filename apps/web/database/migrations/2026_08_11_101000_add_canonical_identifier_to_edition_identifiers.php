<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store the canonical identity of every edition identifier alongside the
 * declared one. An ISBN-10 and its ISBN-13 equivalent are the SAME
 * identifier; the worker already derives `isbn13` per identifier, so we
 * persist that canonical form and match on it. Additive + nullable —
 * legacy rows are backfilled where a canonical form is derivable in SQL
 * (isbn13/uuid/doi); isbn10 canonicalisation happens on the next write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edition_identifiers', function (Blueprint $table) {
            $table->string('canonical_scheme', 32)->nullable();
            $table->string('canonical_value', 512)->nullable();
            $table->index(['canonical_scheme', 'canonical_value'], 'edition_identifiers_canonical_index');
        });

        // Backfill the trivially-canonical rows (declared form already
        // canonical). ISBN-10 rows need the derived ISBN-13 the worker
        // computes and are left for the next ingest to populate.
        foreach (['isbn13', 'uuid', 'doi'] as $scheme) {
            DB::table('edition_identifiers')
                ->where('scheme', $scheme)
                ->whereNull('canonical_value')
                ->update([
                    'canonical_scheme' => $scheme,
                    'canonical_value' => DB::raw('value'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('edition_identifiers', function (Blueprint $table) {
            $table->dropIndex('edition_identifiers_canonical_index');
            $table->dropColumn(['canonical_scheme', 'canonical_value']);
        });
    }
};
