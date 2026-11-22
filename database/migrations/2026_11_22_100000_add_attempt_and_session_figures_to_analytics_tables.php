<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What was played on a visit (game_attempts, game_events), kept on its
        // row: the attempts started on it, the clicks on their results' share
        // buttons, and the attempts with such a click — what the Overview and
        // Site engagement add up per visit. And, for a visit credited with an
        // account, the post its session opened first — none when the session
        // opened none — what Site engagement credits the account to.
        Schema::table('analytics_visits', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('share_clicks')->default(0);
            $table->unsignedInteger('shared_results')->default(0);
            $table->unsignedBigInteger('session_post_id')->nullable();
        });

        // The same sums on the cells of each day's people.
        Schema::table('analytics_cells', function (Blueprint $table) {
            $table->unsignedBigInteger('attempts')->default(0);
            $table->unsignedBigInteger('share_clicks')->default(0);
            $table->unsignedBigInteger('shared_results')->default(0);
        });

        // The accounts credited to a network day's visits by device and the
        // post their session opened first (AnalyticsFirstPostCell), as a
        // roaring bitmap of their browsers' numbers — no post for the accounts
        // whose session opened none — so an account counts once over any days
        // without a session read. Written only on PostgreSQL with the
        // roaringbitmap extension, with the cells.
        Schema::create('analytics_first_post_cells', function (Blueprint $table) {
            $table->id();
            $table->char('network_day', 10);
            $table->string('device', 8);
            $table->unsignedBigInteger('post_id')->nullable();
            $table->binary('accounts')->nullable();

            $table->index(['network_day', 'device'], 'analytics_first_post_cells_day_device_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_first_post_cells');

        Schema::table('analytics_cells', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'share_clicks', 'shared_results']);
        });

        Schema::table('analytics_visits', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'share_clicks', 'shared_results', 'session_post_id']);
        });
    }
};
