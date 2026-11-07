<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discussion generations: one row per attempt a publication plan asked for —
 * a whole discussion, or one part of its discussion written again — what it
 * was written from, and the validated proposal or why there is none. Every
 * attempt is kept, so a new one never takes an earlier one away. The
 * proposal and the call's metadata only; never the prompt, the photo, a key
 * or what the provider sent back beyond the validated proposal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_plan_discussion_generations', function (Blueprint $table) {
            $table->id();

            // Gone with the plan.
            $table->foreignId('studio_plan_id')->constrained('studio_plans')->cascadeOnDelete();
            // Who asked. Accounts become tombstones and are never deleted, so
            // nothing may drop the reference silently.
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();

            $table->string('mode', 32);
            // The comment written again, while it is there: a comment deleted
            // from the draft leaves the history, its id kept in `parameters`.
            $table->foreignId('target_node_id')->nullable()->constrained('studio_plan_discussion_nodes')->nullOnDelete();
            // The Personas taking part, ascending, and everything asked for —
            // the mode, the target's id, the counts, the tone, the version.
            $table->json('persona_user_ids');
            $table->json('parameters');

            // What it was written from, to tell whether it still fits the plan:
            // the plan's version then; the photo's id — no foreign key, so the
            // history never holds a released photo back from its purge — and
            // its analysis (StudioImageDescription::hash()); the post's texts
            // with the discussion as it was (StudioDiscussionContext); and the
            // Personas' characters as they were.
            $table->unsignedInteger('source_lock_version');
            $table->unsignedBigInteger('source_image_asset_id');
            $table->char('source_analysis_hash', 64);
            $table->char('source_discussion_hash', 64);
            $table->char('source_personas_hash', 64);

            // Its state, its attempt id — so a job delivered twice claims it
            // once — and why it failed when it did. Codes are plain strings,
            // cast in the model, so a new one needs no schema change.
            $table->enum('status', ['queued', 'running', 'completed', 'failed']);
            $table->uuid('attempt_id')->unique();
            $table->string('error_code', 64)->nullable();

            // The proposal, once written and validated.
            $table->json('result')->nullable();

            $table->string('model', 128)->nullable();
            $table->string('request_id')->nullable();
            // As the provider reported them; null when it did not, never 0.
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // UTC. Datetimes rather than timestamps, so MariaDB stores the UTC
            // wall time as written. `applied_at` is when an administrator took
            // the proposal into the plan's discussion — apart from Completed,
            // which says only that one was written.
            $table->dateTime('requested_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('applied_at')->nullable();

            $table->timestamps();

            // A plan's generations newest first, and the one on its way.
            $table->index(['studio_plan_id', 'status'], 'studio_discussion_generations_plan_status_index');
            $table->index('target_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_plan_discussion_generations');
    }
};
