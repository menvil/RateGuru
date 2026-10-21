<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each kind of vote carries its own fields and none of the others', and a
     * target key of its own kind: `post`, `comment:{id}`, `rating:{id}`. The
     * key itself is the model's to compute (StudioPlanVote); the database
     * holds the shape.
     */
    private const TARGET_CHECK = <<<'SQL'
        (type = 'vote_post' AND vote_type IS NOT NULL AND discussion_node_id IS NULL
            AND rating_group_id IS NULL AND rating_option_id IS NULL AND target_key = 'post')
        OR (type = 'vote_comment' AND vote_type IS NOT NULL AND discussion_node_id IS NOT NULL
            AND rating_group_id IS NULL AND rating_option_id IS NULL AND target_key LIKE 'comment:%')
        OR (type = 'vote_rating' AND vote_type IS NULL AND discussion_node_id IS NULL
            AND rating_group_id IS NOT NULL AND rating_option_id IS NOT NULL AND target_key LIKE 'rating:%')
        SQL;

    /**
     * From the post's own moment to thirty days after it, and a place among
     * votes of one moment, as StudioVoteValidationRules has them.
     */
    private const BOUNDS_CHECK = 'offset_seconds >= 0 AND offset_seconds <= 2592000 AND sort_order >= 0 AND sort_order <= 65535';

    public function up(): void
    {
        // A vote names one comment of its own plan's discussion by the
        // comment's id and the plan's together, so not even the database lets
        // it reach another plan's comment.
        Schema::table('studio_plan_discussion_nodes', function (Blueprint $table) {
            $table->unique(['id', 'studio_plan_id'], 'studio_discussion_nodes_id_plan_unique');
        });

        // The votes a Draft prepares for its post, its comments and its
        // rating questions, each to be cast later by a Persona through the
        // vote a member casts. A row is an intention only — no vote exists for
        // it — and keeps its id through every edit.
        if (DB::getDriverName() === 'sqlite') {
            // Created whole: SQLite takes a CHECK only with its table.
            $this->createSqliteTable();
        } else {
            Schema::create('studio_plan_votes', function (Blueprint $table) {
                $table->id();
                // Gone with the plan: only a Draft is deleted.
                $table->foreignId('studio_plan_id')->constrained('studio_plans')->cascadeOnDelete();
                // The Persona who is to vote. Accounts become tombstones and
                // are never deleted, so nothing may drop the reference, nor
                // give the vote to anybody else.
                $table->foreignId('persona_user_id')->constrained('users')->restrictOnDelete();
                // Closed sets, checked by the database on every engine.
                $table->enum('type', ['vote_post', 'vote_comment', 'vote_rating']);
                $table->enum('vote_type', ['up', 'down'])->nullable();
                // The prepared comment it is on, gone with that comment.
                $table->unsignedBigInteger('discussion_node_id')->nullable();
                // The rating question and the option chosen, as ids only: the
                // rating configuration stays its own to change. An option
                // deleted or archived later leaves the vote naming it, never
                // another one, to be found by the checks before approval.
                $table->unsignedBigInteger('rating_group_id')->nullable();
                $table->unsignedBigInteger('rating_option_id')->nullable();
                // What it is a vote on, for the one vote a Persona may cast on it.
                $table->string('target_key', 40);
                // How long after the post it is cast, in seconds: relative, so
                // a new date for the post moves every vote with it.
                $table->unsignedInteger('offset_seconds');
                // Where it stands among votes of one moment; equal places fall back to the id.
                $table->unsignedInteger('sort_order');
                $table->timestamps();

                $table->index(['discussion_node_id', 'studio_plan_id'], 'studio_plan_votes_node_index');
                $table->foreign(['discussion_node_id', 'studio_plan_id'], 'studio_plan_votes_node_foreign')
                    ->references(['id', 'studio_plan_id'])
                    ->on('studio_plan_discussion_nodes')
                    ->cascadeOnDelete();
            });

            DB::statement('ALTER TABLE studio_plan_votes ADD CONSTRAINT studio_plan_votes_target_check CHECK ('.self::TARGET_CHECK.')');
            DB::statement('ALTER TABLE studio_plan_votes ADD CONSTRAINT studio_plan_votes_bounds_check CHECK ('.self::BOUNDS_CHECK.')');
        }

        Schema::table('studio_plan_votes', function (Blueprint $table) {
            // One vote of a Persona per target: the post, one comment, one
            // rating question — whichever way it goes. No column of it is null.
            $table->unique(['studio_plan_id', 'persona_user_id', 'target_key'], 'studio_plan_votes_target_unique');
            // A plan's votes in their order; a Persona's prepared votes.
            // Named: the generated names pass MariaDB's 64 characters.
            $table->index(['studio_plan_id', 'offset_seconds', 'sort_order', 'id'], 'studio_plan_votes_plan_order_index');
            $table->index('persona_user_id', 'studio_plan_votes_persona_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_plan_votes');

        Schema::table('studio_plan_discussion_nodes', function (Blueprint $table) {
            $table->dropUnique('studio_discussion_nodes_id_plan_unique');
        });
    }

    private function createSqliteTable(): void
    {
        DB::statement(sprintf(<<<'SQL'
            CREATE TABLE "studio_plan_votes" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                "studio_plan_id" INTEGER NOT NULL,
                "persona_user_id" INTEGER NOT NULL,
                "type" VARCHAR NOT NULL CHECK ("type" IN ('vote_post', 'vote_comment', 'vote_rating')),
                "vote_type" VARCHAR CHECK ("vote_type" IN ('up', 'down')),
                "discussion_node_id" INTEGER,
                "rating_group_id" INTEGER,
                "rating_option_id" INTEGER,
                "target_key" VARCHAR NOT NULL,
                "offset_seconds" INTEGER NOT NULL,
                "sort_order" INTEGER NOT NULL,
                "created_at" DATETIME,
                "updated_at" DATETIME,
                FOREIGN KEY ("studio_plan_id") REFERENCES "studio_plans" ("id") ON DELETE CASCADE,
                FOREIGN KEY ("persona_user_id") REFERENCES "users" ("id") ON DELETE RESTRICT,
                CONSTRAINT "studio_plan_votes_node_foreign" FOREIGN KEY ("discussion_node_id", "studio_plan_id")
                    REFERENCES "studio_plan_discussion_nodes" ("id", "studio_plan_id") ON DELETE CASCADE,
                CONSTRAINT "studio_plan_votes_target_check" CHECK (%s),
                CONSTRAINT "studio_plan_votes_bounds_check" CHECK (%s)
            )
            SQL, self::TARGET_CHECK, self::BOUNDS_CHECK));

        Schema::table('studio_plan_votes', function (Blueprint $table) {
            $table->index(['discussion_node_id', 'studio_plan_id'], 'studio_plan_votes_node_index');
        });
    }
};
