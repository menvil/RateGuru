<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rating_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rating_option_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'post_id', 'rating_group_id']);
            $table->index(['post_id', 'rating_group_id']);
            $table->index('rating_option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_votes');
    }
};
