<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent filesystem-discovery manifest. Discovery is READ-ONLY
     * with respect to the source library: it only records what exists.
     * A separate import step consumes entries and creates submissions.
     */
    public function up(): void
    {
        Schema::create('discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('source_name', 128)->index();
            // Audit snapshot; import re-resolves the root via the
            // MNEMOSYNE_IMPORT_SOURCES allowlist, never from this value.
            $table->string('root_path', 1024);
            // running | completed | aborted | failed
            $table->string('status', 32)->default('running')->index();
            // Resume cursor: last persisted relative path in the
            // deterministic (segment-wise lexicographic DFS) scan order.
            $table->string('last_path', 1024)->nullable();
            $table->unsignedBigInteger('files_seen')->default(0);
            $table->unsignedBigInteger('epubs_found')->default(0);
            $table->unsignedBigInteger('entries_created')->default(0);
            $table->unsignedInteger('skipped_outside_root')->default(0);
            $table->unsignedInteger('unreadable')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('discovery_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discovery_run_id')->constrained()->cascadeOnDelete();
            $table->string('relative_path', 1024);
            $table->unsignedBigInteger('size_bytes')->nullable();
            // Author/Title directory names — HINTS only, never truth.
            $table->string('author_hint', 512)->nullable();
            $table->string('title_hint', 512)->nullable();
            // discovered | imported | import_failed
            $table->string('status', 32)->default('discovered');
            $table->foreignId('book_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->string('error', 1024)->nullable();
            $table->timestamps();

            $table->unique(['discovery_run_id', 'relative_path']);
            $table->index(['discovery_run_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_entries');
        Schema::dropIfExists('discovery_runs');
    }
};
