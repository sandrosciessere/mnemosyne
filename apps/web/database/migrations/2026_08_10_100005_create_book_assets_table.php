<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('edition_id')->nullable()->constrained()->nullOnDelete();
            // Exact-file dedup key: streaming SHA-256 of the original EPUB.
            $table->char('sha256', 64)->unique();
            // Fingerprint of the normalized text in reading order; equal
            // values across different files signal a duplicate CANDIDATE,
            // never an automatic merge.
            $table->char('content_sha256', 64)->nullable()->index();
            $table->string('content_fingerprint_version', 16)->nullable();
            $table->string('original_filename', 512);
            // Relative to the data root (e.g. library/original/sha256/ab/cd/<sha>.epub).
            $table->string('storage_path', 1024)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('uncompressed_size_bytes')->nullable();
            $table->string('mime_type', 128)->default('application/epub+zip');
            $table->string('epub_version', 16)->nullable();
            // pending | passed | passed_with_warnings | needs_review | failed
            $table->string('validation_status', 32)->default('pending');
            $table->string('ingestion_status', 32)->default('pending')->index();
            // Pipeline version of the newest completed artifact set.
            $table->string('pipeline_version', 16)->nullable();
            // Normalized bibliographic metadata extracted by the parser
            // (raw OPF snapshot lives in the metadata.json artifact).
            $table->jsonb('extracted_metadata')->nullable();
            // {spine_items, sections, nodes, text_chars, toc_entries, warnings}
            $table->jsonb('structure_summary')->nullable();
            // {method, evidence, confidence: exact|high_confidence|candidate|unresolved, version}
            $table->jsonb('reconciliation')->nullable();
            $table->timestamps();

            $table->index(['ingestion_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_assets');
    }
};
