<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What Growth → Analytics and a game's Analytics read of each visit,
        // and nothing else: one row a visit, the browser as a number (the
        // first 64 bits of its hash), each step a flag rather than the
        // moment, the sums, and the columns a split or a filter names. A visit is several times wider than this,
        // and the people of a period are counted by sorting every visit of it,
        // so reading these rows is what keeps a tab of a busy site quick.
        // Written only by analytics:refresh-visits from traffic_visits — the
        // days still changing every minute, an older day when something
        // changes it — and gone with its visit.
        Schema::create('analytics_visits', function (Blueprint $table) {
            $table->foreignId('id')->primary()->constrained('traffic_visits')->cascadeOnDelete();
            $table->char('network_day', 10);
            $table->dateTime('arrived_at');
            // The visit the session began with: the session's visits share it.
            $table->unsignedBigInteger('entry_visit_id')->nullable();
            $table->string('channel', 16);
            // The traffic source a paid click came from, and its campaign.
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            // The first 64 bits of visitor_hash, read as a signed integer.
            $table->bigInteger('visitor_key');
            // The hash itself, only of a visit the tracker counts (a game's or a paid click): what a game's
            // Analytics matches its plays and views by.
            $table->char('visitor_hash', 64)->nullable();
            // When the visit was last seen: whether it ran into a game's window from before.
            $table->dateTime('last_seen_at');
            $table->boolean('opened')->default(false);
            $table->boolean('known')->default(false);
            // Known because the browser was signed in when it came.
            $table->boolean('signed_in')->default(false);
            $table->boolean('started')->default(false);
            $table->boolean('completed')->default(false);
            $table->boolean('goal')->default(false);
            $table->boolean('signup_form')->default(false);
            $table->boolean('signed_up')->default(false);
            $table->boolean('account')->default(false);
            $table->boolean('activated')->default(false);
            $table->boolean('post_published')->default(false);
            $table->boolean('returned')->default(false);
            // When the visit's sign-up was: whether a confirmed account's days to activate are over, whether one not confirmed still waits.
            $table->dateTime('signup_at')->nullable();
            $table->unsignedInteger('signup_after_seconds')->nullable();
            $table->unsignedInteger('account_after_seconds')->nullable();
            $table->unsignedSmallInteger('votes')->default(0);
            $table->unsignedSmallInteger('comments')->default(0);
            $table->unsignedSmallInteger('posts_created')->default(0);
            $table->decimal('spend', 18, 8)->default(0);
            $table->boolean('spend_estimated')->default(false);
            $table->decimal('value_real', 14, 8)->default(0);
            $table->decimal('value_expected', 14, 8)->default(0);
            // A campaign tag named where the visit came from (utm_source or utm_medium).
            $table->boolean('tagged')->default(false);
            // A click a crawler or link preview made.
            $table->boolean('crawler')->default(false);
            $table->string('source_key', 140)->nullable();
            $table->string('campaign_key', 140)->nullable();
            $table->string('creative_key', 140)->nullable();
            $table->string('landing_key', 60)->nullable();
            $table->string('device', 8);
            $table->char('country', 2)->nullable();
            $table->string('city', 80)->nullable();
            $table->unsignedBigInteger('game_id')->nullable();
            $table->unsignedBigInteger('version_id')->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('utm_content', 120)->nullable();
            $table->string('utm_term', 120)->nullable();

            // A period is its days. No index leads with the browser: a planner
            // would then walk it for the people's order instead of sorting,
            // which reads the table at random.
            $table->index('network_day', 'analytics_visits_day_index');
            $table->index(['source_key', 'network_day'], 'analytics_visits_source_day_index');
            $table->index('entry_visit_id', 'analytics_visits_entry_index');
            // A game's Analytics reads its visits by the moment they arrived.
            $table->index(['game_id', 'arrived_at'], 'analytics_visits_game_arrived_index');
        });

        // The first network day each browser's page ran on, as far back as
        // visits are kept: whether a visitor of a period had come before it.
        Schema::create('analytics_people', function (Blueprint $table) {
            $table->bigInteger('visitor_key')->primary();
            $table->char('first_day', 10);
        });

        // Network days older than the ones refreshed every minute that
        // something changed — a late confirmation, spend shared out again —
        // each with a token rewritten on every change, so one that comes
        // while the day is refreshed is never lost, and the moment it was
        // first marked, so a day left unread too long is noticed.
        Schema::create('analytics_dirty_days', function (Blueprint $table) {
            $table->char('day', 10)->primary();
            $table->string('token', 26);
            $table->dateTime('marked_at');
            $table->index('marked_at', 'analytics_dirty_days_marked_index');
        });

        // How far the rows of analytics_visits are: the day they were last
        // filled up to on the way to every day, when every day first was, and
        // when they were last brought up to date. One row.
        Schema::create('analytics_rollup_states', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('filled_through', 10)->nullable();
            $table->dateTime('filled_at')->nullable();
            $table->dateTime('refreshed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_rollup_states');
        Schema::dropIfExists('analytics_dirty_days');
        Schema::dropIfExists('analytics_people');
        Schema::dropIfExists('analytics_visits');
    }
};
