<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How far the visitors each traffic period sent to each version got,
        // an hour of assignments at a time (AnalyticsPeriodHour): of the
        // assignments a period made to a version in one UTC hour, how many
        // there were and how many of them started, completed, saw a result,
        // left an email and reached the goal, and their attempts. Each
        // assignment is of one period, one version and one hour, so the hours
        // of a period add up to the period. Written only by
        // analytics:refresh-visits from the assignments, once an hour is over
        // and again whenever something changes one of its assignments.
        // Portable: no extension, every engine.
        Schema::create('analytics_period_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('version_id');
            $table->dateTime('assigned_hour');

            foreach (['assigned', 'started', 'completed', 'result_viewed', 'emailed', 'goal', 'attempts', 'attempts_completed'] as $count) {
                $table->unsignedInteger($count)->default(0);
            }

            $table->index(['period_id', 'assigned_hour'], 'analytics_period_hours_period_hour_index');
            $table->index('assigned_hour', 'analytics_period_hours_hour_index');
        });

        // The confirmed accounts credited to the attempts of the same
        // assignments, by the UTC day each signed up on — what decides whether
        // its days to activate are over — and of them the activated.
        Schema::create('analytics_period_hour_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('version_id');
            $table->dateTime('assigned_hour');
            $table->char('signup_day', 10);
            $table->unsignedInteger('accounts')->default(0);
            $table->unsignedInteger('activated')->default(0);

            $table->index(['period_id', 'assigned_hour'], 'analytics_period_hour_accounts_period_hour_index');
            $table->index('assigned_hour', 'analytics_period_hour_accounts_hour_index');
        });

        // An hour already counted that something changed an assignment of
        // since (AnalyticsDirtyPeriodHour): until it is counted again, the
        // funnel reads its assignments themselves. The token is new on every
        // change.
        Schema::create('analytics_dirty_period_hours', function (Blueprint $table) {
            $table->dateTime('assigned_hour')->primary();
            $table->string('token', 26);
            $table->dateTime('marked_at');
        });

        // The hours are counted in the order the assignments were made, each
        // found by the moment it was made.
        Schema::table('game_assignments', function (Blueprint $table) {
            $table->index('assigned_at', 'game_assignments_assigned_index');
        });

        // How far the hours are counted: every hour up to this one is, once
        // it was over and every change to it could no longer be in flight.
        Schema::table('analytics_rollup_states', function (Blueprint $table) {
            $table->dateTime('period_hours_through')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_rollup_states', function (Blueprint $table) {
            $table->dropColumn('period_hours_through');
        });

        Schema::table('game_assignments', function (Blueprint $table) {
            $table->dropIndex('game_assignments_assigned_index');
        });

        Schema::dropIfExists('analytics_dirty_period_hours');
        Schema::dropIfExists('analytics_period_hour_accounts');
        Schema::dropIfExists('analytics_period_hours');
    }
};
