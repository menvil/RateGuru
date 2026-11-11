<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One question of a content set. Its options are game_round_options.
        Schema::create('game_rounds', function (Blueprint $table) {
            $table->id();
            // Gone with its set: a set is deleted only while no version used it.
            $table->foreignId('content_set_id')->constrained('game_content_sets')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('internal_name', 80);
            $table->string('question', 120)->nullable();
            $table->json('question_translations')->nullable();
            $table->string('description', 240)->nullable();
            $table->json('description_translations')->nullable();
            $table->string('reveal_message', 200)->nullable();
            $table->json('reveal_message_translations')->nullable();
            // Closed set, checked by the database on every engine.
            $table->enum('mode', ['crowd', 'objective'])->default('crowd');
            $table->boolean('unscored')->default(false);
            $table->boolean('show_crowd')->default(true);
            $table->timestamps();

            $table->index(['content_set_id', 'position'], 'game_rounds_set_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rounds');
    }
};
