<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A game as an administrator edits it: its identity and its cover.
        // What visitors play is never this row's content but a published
        // version's snapshot (game_versions).
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            // The public address. Locked once a version has been published,
            // because shared links and ads point at it.
            $table->string('slug', 80)->unique();
            $table->string('internal_name', 120);
            $table->string('title', 120);
            $table->json('title_translations')->nullable();
            $table->string('description', 240)->nullable();
            $table->json('description_translations')->nullable();
            // A growing set (GameMechanic): a plain string, so a new mechanic
            // needs no table rebuild on SQLite.
            $table->string('mechanic', 40);
            // Closed set, checked by the database on every engine.
            $table->enum('status', ['draft', 'live', 'paused', 'archived'])->default('draft');
            // Restricted: only the media lifecycle removes an asset, and it
            // never removes one a game still points at (MediaReferenceChecker).
            $table->foreignId('cover_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            // The last number handed to a version. Grows under the game's row
            // lock, so numbers are never reused, even after a gap.
            $table->unsignedInteger('last_version_number')->default(0);
            $table->dateTime('first_published_at')->nullable();
            $table->dateTime('status_changed_at')->nullable();
            // Accounts become tombstones and are never deleted.
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            // Grows with every save, so a save made from an older copy is
            // refused instead of writing over a newer one.
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index('status', 'games_status_index');
            $table->index('cover_asset_id', 'games_cover_asset_index');
            $table->index('created_by_user_id', 'games_created_by_index');
            $table->index('updated_by_user_id', 'games_updated_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
