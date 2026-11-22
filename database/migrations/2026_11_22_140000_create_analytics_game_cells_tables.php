<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        // A short number for each guest's browser a game counted — the hash
        // of its cookie, the very key a game's figures tell guests apart by —
        // given once, in turn, and never given again: a game's people of a
        // day are a set of these numbers (analytics_game_cells). A row goes
        // only once no cell holds its number, never the highest, which the
        // next number counts on from. A player is counted by their account's
        // own id, which needs no number.
        Schema::create('analytics_game_pids', function (Blueprint $table) {
            $table->char('visitor_hash', 64);
            $table->unsignedInteger('pid')->unique('analytics_game_pids_pid_unique');

            $table->primary('visitor_hash', 'analytics_game_pids_primary');
        });

        // The people of a game's UTC day, per audience and version: those
        // who saw it (a `view`), who started an attempt that day, who
        // finished one of those attempts, whose attempt reached the goal,
        // who started two or more of them, and whose attempts were shared,
        // began a sign-up, went on to a post, the feed, a page or the next
        // game, or voted or rated there — each a roaring bitmap of their
        // numbers — the accounts those attempts signed in, by their ids; and
        // the attempts' sums: started, completed, goal reached, with a
        // confirmed account credited, shared, and with nobody to tell them
        // apart by. A day counted whole has no moment; a day still
        // open is counted up to a moment, every ten minutes or so, the
        // latest of those counts beside the ones before it for a while.
        // Written only on PostgreSQL with the roaringbitmap extension
        // (RefreshAnalyticsGameCellsAction); elsewhere it stays empty and a
        // game's figures read its events and attempts.
        Schema::create('analytics_game_cells', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->char('day', 10);
            $table->string('audience', 16);
            $table->unsignedBigInteger('version_id')->nullable();
            $table->dateTime('moment')->nullable();

            foreach (['viewers', 'starters', 'finishers', 'goal_people', 'repeaters', 'sharers', 'sign_up_starters', 'went_to_post', 'voted_on_post', 'rated_post', 'went_to_feed', 'voted_in_feed', 'went_to_page', 'went_to_next_game', 'signed_in'] as $people) {
                $table->binary($people)->nullable();
            }

            foreach (['attempts', 'completed', 'goal_reached', 'registered', 'shared', 'unnamed'] as $count) {
                $table->unsignedInteger($count)->default(0);
            }

            $table->index(['game_id', 'day'], 'analytics_game_cells_game_day_index');
            $table->index('day', 'analytics_game_cells_day_index');
            $table->index('moment', 'analytics_game_cells_moment_index');
        });

        // A day of a game's cells something changed since it was counted
        // (AnalyticsDirtyGameDay): until it is counted again, a game's
        // figures read its events and attempts themselves. The token is new
        // on every change.
        Schema::create('analytics_dirty_game_days', function (Blueprint $table) {
            $table->char('day', 10);
            $table->string('token', 26);
            $table->dateTime('marked_at');

            $table->primary('day', 'analytics_dirty_game_days_primary');
        });

        // An account confirmed since an open day was counted up to its
        // moment is found by when it was confirmed.
        Schema::table('account_attributions', function (Blueprint $table) {
            $table->index('confirmed_at', 'account_attributions_confirmed_index');
        });

        // The attempts of a window a way on sent to a post — what a game's
        // figures read the sign-ins on the post from, the cells keeping none
        // of it — found by their way on, rather than every attempt of the
        // window read to find the few. An index alone, so SQLite adds it in
        // place.
        Schema::table('game_attempts', function (Blueprint $table) {
            $table->index(['game_id', 'onward_kind', 'started_at'], 'game_attempts_game_onward_index');
        });

        // How far the game cells are: the last day every day up to which is
        // counted whole, the moment the days after it are counted up to, and
        // when every day first was.
        Schema::table('analytics_rollup_states', function (Blueprint $table) {
            $table->char('game_cells_through', 10)->nullable();
            $table->dateTime('game_cells_moment')->nullable();
            $table->dateTime('game_cells_filled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_rollup_states', function (Blueprint $table) {
            $table->dropColumn(['game_cells_through', 'game_cells_moment', 'game_cells_filled_at']);
        });

        Schema::table('game_attempts', function (Blueprint $table) {
            $table->dropIndex('game_attempts_game_onward_index');
        });

        Schema::table('account_attributions', function (Blueprint $table) {
            $table->dropIndex('account_attributions_confirmed_index');
        });

        Schema::dropIfExists('analytics_dirty_game_days');
        Schema::dropIfExists('analytics_game_cells');
        Schema::dropIfExists('analytics_game_pids');
    }
};
