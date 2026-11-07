<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Content Studio's business queue: one row per scheduled domain write
        // of an approved plan — its post, and later its comments, replies and
        // votes — kept in the main database, so backups carry it and nothing
        // is lost with a queue. The queue itself carries only a row's id.
        Schema::create('studio_plan_actions', function (Blueprint $table) {
            $table->id();
            // Gone with the plan: only a Draft is deleted, and a Draft has none.
            $table->foreignId('studio_plan_id')->constrained('studio_plans')->cascadeOnDelete();
            // The action this one waits for, such as the post a comment is on.
            $table->foreignId('parent_action_id')->nullable()->constrained('studio_plan_actions')->cascadeOnDelete();
            // Closed sets, checked by the database on every engine.
            $table->enum('type', ['create_post', 'create_comment', 'create_reply', 'vote_post', 'vote_comment', 'vote_rating']);
            // The Persona it is done as. Accounts become tombstones and are
            // never deleted, so nothing may drop the reference silently.
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('payload');
            // When it happens, as the record it writes says: a post's
            // published_at, past or future. Dates, not TIMESTAMPs, like the
            // plan's own.
            $table->dateTime('occurs_at');
            // When it may run at the earliest.
            $table->dateTime('run_after');
            $table->enum('status', ['pending', 'held', 'dispatched', 'running', 'done', 'failed', 'cancelled'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            // When it actually ran.
            $table->dateTime('finished_at')->nullable();
            // What it wrote, as reports name their target.
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            // What is due, in order; a plan's actions; an action's children;
            // a Persona's actions by state; and those sent or claimed too long
            // ago to still be on their way.
            $table->index(['status', 'run_after']);
            $table->index('studio_plan_id');
            $table->index('parent_action_id');
            $table->index(['actor_user_id', 'status']);
            $table->index(['status', 'dispatched_at']);
            $table->index(['status', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_plan_actions');
    }
};
