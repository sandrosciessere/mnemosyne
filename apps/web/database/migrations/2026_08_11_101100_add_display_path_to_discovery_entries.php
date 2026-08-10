<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lossless, byte-exact discovery paths.
     *
     * ext4 path components are arbitrary bytes; real libraries contain
     * latin-1 (non-UTF-8) filenames. The authoritative `relative_path`
     * column now stores the base64 of the RAW path bytes (bounded ASCII,
     * fits varchar+btree, and distinct for e.g. "caf\xe9" vs "caf\xe8").
     * The per-run unique(discovery_run_id, relative_path) therefore keeps
     * byte-distinct files distinct, and import decodes it back to the exact
     * bytes to locate the file. PostgreSQL text/varchar cannot store invalid
     * UTF-8 at all, so a separate best-effort human-readable `display_path`
     * (valid UTF-8, invalid bytes -> U+FFFD) is added for UI/logs only.
     *
     * Additive + reversible; safe on PostgreSQL and SQLite (plain nullable
     * column, no FK/unique changes, no table rebuild).
     */
    public function up(): void
    {
        Schema::table('discovery_entries', function (Blueprint $table) {
            // Human-readable, non-authoritative. Never used for uniqueness
            // or to reconstruct the on-disk path.
            $table->string('display_path', 1024)->nullable()->after('relative_path');
        });
    }

    public function down(): void
    {
        Schema::table('discovery_entries', function (Blueprint $table) {
            $table->dropColumn('display_path');
        });
    }
};
