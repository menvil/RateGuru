<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_author_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rating_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rating_option_id');
            $table->timestamps();

            // Composite FK (same pattern as rating_votes): guarantees at the DB level
            // that the chosen option actually belongs to the answered group.
            $table->foreign(['rating_option_id', 'rating_group_id'])
                ->references(['id', 'rating_group_id'])
                ->on('rating_options')
                ->cascadeOnDelete();
            $table->unique(['post_id', 'rating_group_id']);
            $table->index('rating_option_id');
            $table->index(['rating_group_id', 'rating_option_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_author_answers');
    }
};
