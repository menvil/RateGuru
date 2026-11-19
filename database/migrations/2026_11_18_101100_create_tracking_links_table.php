<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An administrator's tagged address for a post, an ad or an email:
        // where it leads (TrackingLinkDestination — a game, one of the
        // site's pages, or a path of the administrator's own) and the five
        // campaign tags it carries. A link has no parameter of its own: a
        // visit is the link's when its session came by the same five tags
        // (`traffic_visits.utm_*`), so no two links may carry the same five,
        // the content and the term kept as '' when there are none so the
        // unique index sees them. Kept for good. Games are archived, never
        // deleted, and the people who make links are administrators, whose
        // accounts become tombstones: every reference restricts.
        Schema::create('tracking_links', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('destination', 16);
            $table->foreignId('game_id')->nullable()->constrained('games')->restrictOnDelete();
            $table->string('path', 255);
            $table->string('utm_source', 64);
            $table->string('utm_medium', 64);
            $table->string('utm_campaign', 120);
            $table->string('utm_content', 120)->default('');
            $table->string('utm_term', 120)->default('');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'], 'tracking_links_tags_unique');
            // The lookups each foreign key's delete check goes through.
            $table->index('game_id', 'tracking_links_game_index');
            $table->index('created_by_user_id', 'tracking_links_created_by_index');
            $table->index('updated_by_user_id', 'tracking_links_updated_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_links');
    }
};
