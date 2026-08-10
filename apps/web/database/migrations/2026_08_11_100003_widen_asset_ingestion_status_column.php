<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `ready_for_enrichment_with_warnings` (35 chars) exceeds the original
     * varchar(32). Widen the asset status column (and validation_status,
     * for headroom) so status vocabulary can evolve without truncation.
     */
    public function up(): void
    {
        Schema::table('book_assets', function (Blueprint $table) {
            $table->string('ingestion_status', 64)->default('pending')->change();
            $table->string('validation_status', 64)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('book_assets', function (Blueprint $table) {
            $table->string('ingestion_status', 32)->default('pending')->change();
            $table->string('validation_status', 32)->default('pending')->change();
        });
    }
};
