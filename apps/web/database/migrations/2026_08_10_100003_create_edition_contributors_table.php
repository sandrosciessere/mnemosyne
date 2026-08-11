<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edition_contributors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contributor_id')->constrained()->restrictOnDelete();
            // MARC relator code when known (aut, trl, edt, ill, ...); 'oth'
            // when the source EPUB declared no usable role.
            $table->string('role', 16)->default('aut');
            // Name exactly as credited in this edition (pre-normalization).
            $table->string('credited_as', 512);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['edition_id', 'contributor_id', 'role', 'position'], 'edition_contributor_unique');
            $table->index('contributor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edition_contributors');
    }
};
