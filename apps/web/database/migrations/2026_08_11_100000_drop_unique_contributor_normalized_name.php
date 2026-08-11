<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A normalized display name is a matching SIGNAL, never a person's
     * identity: two different humans can share the same name. The unique
     * constraint wrongly collapsed homonyms into one row.
     */
    public function up(): void
    {
        Schema::table('contributors', function (Blueprint $table) {
            $table->dropUnique(['normalized_name']);
            $table->index('normalized_name');
        });
    }

    public function down(): void
    {
        Schema::table('contributors', function (Blueprint $table) {
            $table->dropIndex(['normalized_name']);
            $table->unique('normalized_name');
        });
    }
};
