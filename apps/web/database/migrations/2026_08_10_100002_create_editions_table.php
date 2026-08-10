<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('work_id')->constrained()->restrictOnDelete();
            $table->string('title', 1024);
            $table->string('subtitle', 1024)->nullable();
            $table->string('language', 32)->nullable()->index();
            $table->string('publisher', 512)->nullable();
            $table->string('publication_date', 64)->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('edition_statement', 512)->nullable();
            $table->text('description')->nullable();
            $table->text('rights')->nullable();
            $table->jsonb('subjects')->nullable();
            // Untouched normalized-metadata snapshot this Edition was built
            // from, plus provenance {source, parser_version, opf_path}.
            $table->jsonb('source_metadata')->nullable();
            $table->string('status', 32)->default('provisional')->index();
            $table->timestamps();

            $table->index(['work_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editions');
    }
};
