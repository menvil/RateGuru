<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The discussion a Draft prepares for its post: comments on the post
        // and replies to them, each to be written later by a Persona. A row
        // is a draft only — no comment exists for it — and keeps its id
        // through every edit, so a later step can name one comment exactly.
        Schema::create('studio_plan_discussion_nodes', function (Blueprint $table) {
            $table->id();
            // Gone with the plan: only a Draft is deleted.
            $table->foreignId('studio_plan_id')->constrained('studio_plans')->cascadeOnDelete();
            // The comment a reply answers; none for a comment on the post.
            // Two levels only: a parent is always a comment on the post.
            // Deleting a comment takes its replies with it.
            $table->foreignId('parent_id')->nullable()->constrained('studio_plan_discussion_nodes')->cascadeOnDelete();
            // The Persona who is to write it. Accounts become tombstones and
            // are never deleted, so nothing may drop the reference silently.
            $table->foreignId('persona_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            // Where it stands among its siblings; equal places fall back to the id.
            $table->unsignedInteger('sort_order');
            // How long after the post it is written, in seconds: relative, so
            // a new date for the post moves the whole discussion with it.
            $table->unsignedInteger('offset_seconds');
            $table->timestamps();

            // A plan's discussion in its order; a comment's replies; a
            // Persona's prepared comments.
            // Named: the generated name passes MariaDB's 64 characters.
            $table->index(['studio_plan_id', 'parent_id', 'sort_order', 'id'], 'studio_discussion_nodes_plan_order_index');
            $table->index('parent_id');
            $table->index('persona_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_plan_discussion_nodes');
    }
};
