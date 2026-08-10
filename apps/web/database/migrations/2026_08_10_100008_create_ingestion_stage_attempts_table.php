<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_stage_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingestion_run_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->unsignedSmallInteger('attempt');
            // running | succeeded | failed | needs_review | cancelled
            $table->string('status', 32)->default('running');
            $table->string('handler_version', 32)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            // Safe, bounded message — full technical detail goes to logs,
            // keyed by the run correlation_id.
            $table->string('error_message', 1024)->nullable();
            $table->jsonb('result_summary')->nullable();
            $table->jsonb('worker_meta')->nullable();
            $table->timestamps();

            $table->unique(['ingestion_run_id', 'stage', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_stage_attempts');
    }
};
