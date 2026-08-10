<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributors', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 512);
            $table->string('sort_name', 512)->nullable();
            // Matching signal only — NOT an identity key (see the follow-up
            // migration that drops the original unique constraint: homonyms
            // must never be collapsed by schema).
            $table->string('normalized_name', 512)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributors');
    }
};
