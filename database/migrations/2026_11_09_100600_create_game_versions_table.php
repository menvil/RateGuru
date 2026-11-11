<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A published, playable game: one content set played through one
        // flow, frozen whole into `snapshot` when it was published — rounds,
        // the crowd's votes as they stood, result profiles, flow, texts and
        // their translations. Nothing but its status ever changes afterwards.
        Schema::create('game_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->enum('status', ['active', 'retired'])->default('active');
            // Where it was published from. Restricted: a set or flow a
            // version used is archived, never deleted.
            $table->foreignId('content_set_id')->constrained('game_content_sets')->restrictOnDelete();
            $table->foreignId('flow_id')->constrained('game_flows')->restrictOnDelete();
            $table->json('snapshot');
            // Readers know the shape by this number; a stored snapshot is
            // never rewritten into a newer one.
            $table->unsignedSmallInteger('snapshot_schema');
            $table->unsignedSmallInteger('round_count');
            $table->unsignedSmallInteger('scored_round_count');
            $table->unsignedInteger('max_score');
            $table->foreignId('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at');
            $table->dateTime('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'number'], 'game_versions_game_number_unique');
            $table->index('content_set_id', 'game_versions_content_set_index');
            $table->index('flow_id', 'game_versions_flow_index');
            $table->index('published_by_user_id', 'game_versions_published_by_index');
        });

        // The uploaded pictures a version shows, so the media lifecycle keeps
        // them while the version exists. A post's picture is not listed: the
        // post owns it, and a post that leaves the site takes it along.
        Schema::create('game_version_assets', function (Blueprint $table) {
            $table->foreignId('version_id')->constrained('game_versions')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->restrictOnDelete();

            $table->primary(['version_id', 'media_asset_id']);
            $table->index('media_asset_id', 'game_version_assets_asset_index');
        });

        // The posts a version shows, by id only: history, never a hold on the
        // post. Read to find versions a post leaving the site has made
        // unplayable.
        Schema::create('game_version_posts', function (Blueprint $table) {
            $table->foreignId('version_id')->constrained('game_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('post_id');

            $table->primary(['version_id', 'post_id']);
            $table->index('post_id', 'game_version_posts_post_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_version_posts');
        Schema::dropIfExists('game_version_assets');
        Schema::dropIfExists('game_versions');
    }
};
