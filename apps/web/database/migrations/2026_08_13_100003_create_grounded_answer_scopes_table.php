<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalized per-answer book scope: which BookAssets were authorized
     * AND selected for this answer. Historical auditing must not depend
     * on request JSON in logs. ACL is re-evaluated on every new answer —
     * this table records what was allowed then, not what is allowed now.
     */
    public function up(): void
    {
        Schema::create('grounded_answer_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grounded_answer_run_id')
                ->constrained('grounded_answer_runs')->cascadeOnDelete();
            $table->foreignId('book_asset_id')
                ->constrained('book_assets')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['grounded_answer_run_id', 'book_asset_id'], 'answer_scope_unique');
            $table->index('book_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grounded_answer_scopes');
    }
};
