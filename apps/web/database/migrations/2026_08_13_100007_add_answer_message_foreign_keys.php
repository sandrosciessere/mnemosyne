<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * conversation_messages ↔ grounded_answer_runs reference each other
     * (assistant message → run; run → originating user message), so the
     * FKs are added after both tables exist. Both are SET NULL: deleting
     * either side never cascades into the other, and the surviving row
     * keeps its own audit value.
     */
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->foreign('grounded_answer_run_id')
                ->references('id')->on('grounded_answer_runs')->nullOnDelete();
        });

        Schema::table('grounded_answer_runs', function (Blueprint $table) {
            $table->foreign('user_message_id')
                ->references('id')->on('conversation_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grounded_answer_runs', function (Blueprint $table) {
            $table->dropForeign(['user_message_id']);
        });

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropForeign(['grounded_answer_run_id']);
        });
    }
};
