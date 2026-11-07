<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            // Follows are cleaned up explicitly by account anonymization
            // (PR-B), never via FK cascade: users must not be hard-deletable
            // at the DB level at all.
            $table->foreignId('follower_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['follower_id', 'author_id']);
            $table->index(['author_id', 'created_at']);
            $table->index(['follower_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
