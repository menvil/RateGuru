<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a player is told they are, by how many of the scored rounds
        // they got right. A set's profiles must cover every count from none to
        // all exactly once before a version of it is published.
        Schema::create('game_result_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_set_id')->constrained('game_content_sets')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // Stable within its set, and copied into a version: the address
            // of a shared result names it.
            $table->string('key', 40);
            $table->string('name', 60);
            $table->json('name_translations')->nullable();
            $table->string('description', 240)->nullable();
            $table->json('description_translations')->nullable();
            $table->unsignedSmallInteger('min_matches');
            $table->unsignedSmallInteger('max_matches');
            // A growing set of badge names (GameProfileIcon).
            $table->string('icon', 40);
            $table->string('share_text', 200)->nullable();
            $table->json('share_text_translations')->nullable();
            // Restricted: only the media lifecycle removes an asset.
            $table->foreignId('share_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['content_set_id', 'key'], 'game_result_profiles_set_key_unique');
            $table->index('share_asset_id', 'game_result_profiles_share_asset_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_result_profiles');
    }
};
