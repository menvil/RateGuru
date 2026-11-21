<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a visit did with posts (traffic_visit_posts), kept on its row:
        // its rows of posts, the times it opened one, whether it opened any,
        // whether it voted on a post it opened, and the posts it shared — what
        // Site engagement adds up per visit.
        Schema::table('analytics_visits', function (Blueprint $table) {
            $table->unsignedInteger('post_rows')->default(0);
            $table->unsignedInteger('post_views')->default(0);
            $table->boolean('post_viewed')->default(false);
            $table->boolean('post_voted')->default(false);
            $table->unsignedInteger('post_shares')->default(0);
        });

        // The same on the cells of each day's people: the people who opened a
        // post and who voted on one they opened as sets, the views and shares
        // as sums.
        Schema::table('analytics_cells', function (Blueprint $table) {
            $table->binary('post_viewers')->nullable();
            $table->binary('post_voters')->nullable();
            $table->unsignedBigInteger('post_rows')->default(0);
            $table->unsignedBigInteger('post_views')->default(0);
            $table->unsignedBigInteger('post_shares')->default(0);
        });

        // Each post's people a network day and device (AnalyticsPostCell): those
        // who opened it, of them those who voted on it or commented on it, and
        // those who voted on it, as roaring bitmaps of their numbers, with its
        // views, votes and comments — the posts opened most, over any days,
        // without reading a visit. Written only on PostgreSQL with the
        // roaringbitmap extension, with the cells.
        Schema::create('analytics_post_cells', function (Blueprint $table) {
            $table->id();
            $table->char('network_day', 10);
            $table->string('device', 8);
            $table->unsignedBigInteger('post_id');
            $table->binary('viewers')->nullable();
            $table->binary('engaged')->nullable();
            $table->binary('voters')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('votes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);

            $table->index(['network_day', 'device'], 'analytics_post_cells_day_device_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_post_cells');

        Schema::table('analytics_cells', function (Blueprint $table) {
            $table->dropColumn(['post_viewers', 'post_voters', 'post_rows', 'post_views', 'post_shares']);
        });

        Schema::table('analytics_visits', function (Blueprint $table) {
            $table->dropColumn(['post_rows', 'post_views', 'post_viewed', 'post_voted', 'post_shares']);
        });
    }
};
