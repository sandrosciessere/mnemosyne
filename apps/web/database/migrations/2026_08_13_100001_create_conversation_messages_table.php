<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per conversation turn. User messages carry the question
     * text; assistant messages reference the grounded answer run whose
     * VERIFIED claims are the authoritative content (no free-form
     * assistant prose is ever stored as an answer).
     */
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            // user | assistant
            $table->string('role', 16);
            $table->text('content')->nullable();
            $table->foreignId('grounded_answer_run_id')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index('grounded_answer_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
