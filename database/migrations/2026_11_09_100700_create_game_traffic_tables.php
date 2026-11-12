<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A stretch of time one allocation of an audience's visitors was in
        // effect. Applying new shares ends the open period and starts another,
        // unless nobody was assigned in it yet.
        Schema::create('game_traffic_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->enum('audience', ['anonymous', 'authenticated']);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['game_id', 'audience', 'ended_at'], 'game_traffic_periods_game_audience_index');
            $table->index('started_by_user_id', 'game_traffic_periods_started_by_index');
        });

        // A version's whole-number share of its period's visitors. A period
        // with no rows is an explicit "no traffic" for its audience.
        if (DB::getDriverName() === 'sqlite') {
            // Created whole: SQLite takes a CHECK only with its table (docs/architecture/games.md, "Database checks").
            DB::statement(<<<'SQL'
                CREATE TABLE "game_traffic_shares" (
                    "period_id" INTEGER NOT NULL,
                    "version_id" INTEGER NOT NULL,
                    "share_percent" INTEGER NOT NULL,
                    FOREIGN KEY ("period_id") REFERENCES "game_traffic_periods" ("id") ON DELETE CASCADE,
                    FOREIGN KEY ("version_id") REFERENCES "game_versions" ("id") ON DELETE RESTRICT,
                    PRIMARY KEY ("period_id", "version_id"),
                    CONSTRAINT "game_traffic_shares_percent_check" CHECK (share_percent >= 1 AND share_percent <= 100)
                )
                SQL);
        } else {
            Schema::create('game_traffic_shares', function (Blueprint $table) {
                $table->foreignId('period_id')->constrained('game_traffic_periods')->cascadeOnDelete();
                $table->foreignId('version_id')->constrained('game_versions')->restrictOnDelete();
                $table->unsignedTinyInteger('share_percent');

                $table->primary(['period_id', 'version_id']);
            });

            DB::statement('ALTER TABLE game_traffic_shares ADD CONSTRAINT game_traffic_shares_percent_check CHECK (share_percent >= 1 AND share_percent <= 100)');
        }

        Schema::table('game_traffic_shares', function (Blueprint $table) {
            $table->index('version_id', 'game_traffic_shares_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_traffic_shares');
        Schema::dropIfExists('game_traffic_periods');
    }
};
