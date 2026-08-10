<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only timeline. Rows are never updated or deleted by
        // application code; admin actions always record an actor.
        Schema::create('ingestion_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_submission_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ingestion_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['ingestion_run_id', 'id']);
            $table->index(['book_submission_id', 'id']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_events');
    }
};
