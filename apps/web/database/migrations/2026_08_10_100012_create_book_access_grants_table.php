<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Minimal access foundation: a grant lets a user see an asset and
        // download its original file. Full collection ACLs arrive in a
        // later milestone and will supersede (not break) this table.
        Schema::create('book_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_asset_id')->constrained()->cascadeOnDelete();
            $table->string('source', 64)->default('submission');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'book_asset_id']);
            $table->index('book_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_access_grants');
    }
};
