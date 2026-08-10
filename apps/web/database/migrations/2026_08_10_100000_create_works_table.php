<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('canonical_title', 1024);
            $table->string('normalized_title', 1024)->index();
            $table->string('original_language', 32)->nullable();
            $table->string('status', 32)->default('provisional')->index();
            // How this Work came to exist: {method, evidence, confidence, version}
            $table->jsonb('reconciliation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('works');
    }
};
