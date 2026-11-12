<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A post option never holds an uploaded picture. That an upload holds no
     * post is SaveContentSetAction's to keep: post_id is detached by the post
     * purge (ON DELETE SET NULL), and MariaDB refuses a CHECK on a column with
     * a referential action — so no engine checks it, and all three agree.
     */
    private const SOURCE_CHECK = <<<'SQL'
        source = 'upload' OR asset_id IS NULL
        SQL;

    public function up(): void
    {
        // The choices of a round, in their slots: A is 0, B is 1. A versus
        // round has exactly two; another mechanic may have more or fewer.
        if (DB::getDriverName() === 'sqlite') {
            // Created whole: SQLite takes a CHECK only with its table (docs/architecture/games.md, "Database checks").
            $this->createSqliteTable();
        } else {
            Schema::create('game_round_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('round_id')->constrained('game_rounds')->cascadeOnDelete();
                $table->unsignedTinyInteger('position');
                $table->enum('source', ['post', 'upload'])->default('post');
                // A protective detach: the post purge (PostGraphDeletionService)
                // must never be blocked by a draft round, and the round then
                // asks for another post. Published versions keep their own
                // copy of the reference in their snapshot.
                $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
                // Restricted: only the media lifecycle removes an asset, never
                // one a round still shows (MediaReferenceChecker).
                $table->foreignId('asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
                $table->string('label', 60)->nullable();
                $table->json('label_translations')->nullable();
                $table->boolean('is_correct')->default(false);
                $table->timestamps();
            });

            DB::statement('ALTER TABLE game_round_options ADD CONSTRAINT game_round_options_source_shape_check CHECK ('.self::SOURCE_CHECK.')');
        }

        Schema::table('game_round_options', function (Blueprint $table) {
            $table->unique(['round_id', 'position'], 'game_round_options_round_position_unique');
            $table->index('post_id', 'game_round_options_post_index');
            $table->index('asset_id', 'game_round_options_asset_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_round_options');
    }

    private function createSqliteTable(): void
    {
        DB::statement(sprintf(<<<'SQL'
            CREATE TABLE "game_round_options" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                "round_id" INTEGER NOT NULL,
                "position" INTEGER NOT NULL,
                "source" VARCHAR NOT NULL DEFAULT 'post' CHECK ("source" IN ('post', 'upload')),
                "post_id" INTEGER,
                "asset_id" INTEGER,
                "label" VARCHAR,
                "label_translations" TEXT,
                "is_correct" TINYINT(1) NOT NULL DEFAULT 0,
                "created_at" DATETIME,
                "updated_at" DATETIME,
                FOREIGN KEY ("round_id") REFERENCES "game_rounds" ("id") ON DELETE CASCADE,
                FOREIGN KEY ("post_id") REFERENCES "posts" ("id") ON DELETE SET NULL,
                FOREIGN KEY ("asset_id") REFERENCES "media_assets" ("id") ON DELETE RESTRICT,
                CONSTRAINT "game_round_options_source_shape_check" CHECK (%s)
            )
            SQL, self::SOURCE_CHECK));
    }
};
