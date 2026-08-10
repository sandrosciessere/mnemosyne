<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // Null for filesystem/admin-CLI submissions.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 32); // upload | filesystem
            // For filesystem sources: the discovered path relative to the
            // import source root, plus author/title hints. Never trusted.
            $table->jsonb('source_reference')->nullable();
            $table->string('original_filename', 512);
            $table->text('note')->nullable();
            $table->string('status', 32)->default('pending_approval');
            $table->string('priority', 16)->default('normal');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 1024)->nullable();
            // Uploaded file location relative to the data root, until the
            // asset is promoted to content-addressed original storage.
            $table->string('incoming_path', 1024)->nullable();
            $table->unsignedBigInteger('upload_size_bytes')->nullable();
            $table->foreignId('book_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_exact_duplicate')->default(false);
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_submissions');
    }
};
