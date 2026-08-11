<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('book_asset_id')->constrained('book_assets')->cascadeOnDelete();
            $table->foreignId('duplicate_of_asset_id')->constrained('book_assets')->cascadeOnDelete();
            // content_sha256_match today; future signals get new reasons.
            $table->string('reason', 64);
            // {content_sha256, metadata_comparison: {...}} — evidence shown
            // to the admin; resolution is always a human decision.
            $table->jsonb('evidence')->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['book_asset_id', 'duplicate_of_asset_id', 'reason'], 'duplicate_candidate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_candidates');
    }
};
