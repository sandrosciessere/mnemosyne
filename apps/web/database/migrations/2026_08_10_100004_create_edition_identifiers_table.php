<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edition_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            // isbn13 | isbn10 | uuid | doi | uri | other
            $table->string('scheme', 32);
            $table->string('value', 512);
            $table->string('raw_value', 512);
            $table->string('source', 64)->default('epub_opf');
            $table->timestamps();

            $table->unique(['edition_id', 'scheme', 'value']);
            $table->index(['scheme', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edition_identifiers');
    }
};
