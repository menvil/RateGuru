<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The sums of a game's visits a day (AnalyticsGameDay): of the visits
        // that share one network day — what a refresh writes again — one UTC
        // day they arrived on — what a game's figures read between — and one
        // set of keys. Only sums and the first arrival, so the days of a
        // period add up to the period; a sign-up not confirmed keeps its
        // moment as a key, so whether its window to confirm has closed is still
        // a matter of the moment it is read at. By country, and without it
        // ('all') for every read that names no country. Portable: no
        // extension, every engine.
        Schema::create('analytics_game_days', function (Blueprint $table) {
            $table->id();
            $table->string('level', 8);
            $table->char('network_day', 10);
            $table->char('arrival_day', 10);
            $table->unsignedBigInteger('game_id');
            $table->string('channel', 16);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('version_id')->nullable();
            $table->string('device', 8);
            $table->char('country', 2)->nullable();
            $table->boolean('signed_in')->default(false);
            $table->dateTime('unconfirmed_at')->nullable();
            $table->dateTime('first_at');

            foreach (['visits', 'opened', 'started', 'completed', 'goal', 'accounts', 'posted', 'returned', 'known_opened', 'signed_up', 'confirmed', 'confirmed_10_minutes', 'confirmed_hour', 'confirmed_day'] as $count) {
                $table->unsignedInteger($count)->default(0);
            }

            foreach (['spend', 'spend_estimated', 'known_spend', 'value_real', 'value_accounts', 'value_expected'] as $sum) {
                $table->decimal($sum, 20, 8)->default(0);
            }

            $table->index(['level', 'game_id', 'arrival_day'], 'analytics_game_days_level_game_day_index');
            $table->index('network_day', 'analytics_game_days_network_day_index');
        });

        // How far the sums are: the day they were last filled up to on the
        // way to every day, and when every day first was.
        Schema::table('analytics_rollup_states', function (Blueprint $table) {
            $table->char('game_days_through', 10)->nullable();
            $table->dateTime('game_days_filled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_rollup_states', function (Blueprint $table) {
            $table->dropColumn(['game_days_through', 'game_days_filled_at']);
        });

        Schema::dropIfExists('analytics_game_days');
    }
};
