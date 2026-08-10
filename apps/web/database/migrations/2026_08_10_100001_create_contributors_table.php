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
            // Exact-string dedup key only. Two homonymous people will share a
            // row at this stage; future authority resolution can split them
            // because edition_contributors preserves the raw credited name.
            $table->string('normalized_name', 512)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributors');
    }
};
