<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A named collection of rounds with its own scoring and result
        // profiles. A version publishes one set; editing the set afterwards
        // never changes that version.
        Schema::create('game_content_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedTinyInteger('points_per_round')->default(1);
            // How sure the crowd has to be before a round scores by it: the
            // votes each post needs and the gap, in percentage points, its
            // upvote share needs over the other's.
            $table->unsignedInteger('crowd_min_votes')->default(10);
            $table->unsignedTinyInteger('crowd_min_gap')->default(0);
            $table->dateTime('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['game_id', 'position'], 'game_content_sets_game_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_content_sets');
    }
};
