<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The moments of a visitor's way, each written once, when it first
     * happens (TrafficEvent).
     *
     * @var list<string>
     */
    private const array EVENTS = [
        'opened', 'started', 'halfway', 'completed', 'goal', 'email', 'shared', 'replayed',
        'post_opened', 'post_voted', 'post_rated', 'feed_voted', 'next_game', 'page', 'feed',
        'signup_form', 'signup', 'account', 'login', 'post_published', 'returned',
    ];

    public function up(): void
    {
        // One visit: a paid click (any address of the site carrying a known
        // rg_src) or an unpaid arrival on a game. Every report is a sum over
        // these rows, so a row carries its dimensions, the moments it reached,
        // what it cost and what it is worth. Kept 400 days
        // (traffic:purge-expired).
        Schema::create('traffic_visits', function (Blueprint $table) {
            $table->id();
            // Growing sets: TrafficChannel, TrafficKnownReason, TrafficDevice,
            // TrafficSpendBasis, AccountSignupPlace, AccountSignupMethod.
            $table->string('channel', 16);
            $table->foreignId('source_id')->nullable()->constrained('traffic_sources')->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('traffic_campaigns')->restrictOnDelete();
            $table->string('site_ref', 120)->nullable();
            $table->string('zone_ref', 120)->nullable();
            $table->string('creative_ref', 120)->nullable();
            // Where an unpaid visit came from: the referring host, the search
            // engine, the internal surface.
            $table->string('origin', 120)->nullable();
            // SHA-256 of the network's click id: one visit per click.
            $table->char('click_hash', 64)->nullable();
            // SHA-256 of the rg_game_visitor token, as on game attempts.
            $table->char('visitor_hash', 64);
            $table->boolean('known')->default(false);
            $table->string('known_reason', 16)->nullable();
            $table->string('device', 8);
            $table->char('country', 2)->nullable();
            $table->string('region', 8)->nullable();
            $table->string('city', 80)->nullable();
            $table->foreignId('game_id')->nullable()->constrained('games')->restrictOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('game_versions')->restrictOnDelete();
            // The prices in force when the visit came, and its country group
            // in them: a later change of either never recounts it.
            $table->foreignId('price_set_id')->constrained('traffic_price_sets')->restrictOnDelete();
            $table->string('country_group', 24);
            $table->unsignedSmallInteger('worth_percent');
            $table->unsignedSmallInteger('bot_flags')->default(0);
            $table->boolean('blocked_hit')->default(false);
            $table->dateTime('arrived_at');
            // The visit's day in its source's reporting time zone.
            $table->char('network_day', 10);
            // Until when this paid click takes the visitor's later steps.
            $table->dateTime('holds_until')->nullable();
            $table->dateTime('last_seen_at');

            foreach (self::EVENTS as $event) {
                $table->dateTime($event.'_at')->nullable();
            }

            $table->dateTime('late_signup_at')->nullable();
            $table->string('signup_place', 8)->nullable();
            $table->string('signup_method', 16)->nullable();
            // Whole seconds from the click, kept as numbers: date arithmetic
            // is written differently on every engine.
            $table->unsignedInteger('signup_after_seconds')->nullable();
            $table->unsignedInteger('account_after_seconds')->nullable();
            $table->unsignedSmallInteger('rounds_answered')->default(0);
            // The click's own price, when its link carries one; the spend the
            // reports count, and where it came from.
            $table->decimal('cost', 14, 8)->nullable();
            $table->decimal('spend', 18, 8)->default(0);
            $table->string('spend_basis', 16)->default('none');
            $table->boolean('spend_estimated')->default(false);
            $table->decimal('value_real', 14, 8)->default(0);
            $table->decimal('value_expected', 14, 8)->default(0);

            $table->index('arrived_at', 'traffic_visits_arrived_index');
            $table->index(['source_id', 'arrived_at'], 'traffic_visits_source_arrived_index');
            $table->index(['campaign_id', 'arrived_at'], 'traffic_visits_campaign_arrived_index');
            $table->index(['game_id', 'arrived_at'], 'traffic_visits_game_arrived_index');
            $table->index(['visitor_hash', 'id'], 'traffic_visits_visitor_index');
            $table->index('version_id', 'traffic_visits_version_index');
            $table->index('price_set_id', 'traffic_visits_price_set_index');
            $table->unique(['source_id', 'click_hash'], 'traffic_visits_click_unique');
        });

        // The rest of a click, kept 90 days: the network's click id itself
        // (up to 700 characters), the other link parameters, the landing
        // path, the referrer and a keyed hash of the address — never the
        // address.
        Schema::create('traffic_visit_details', function (Blueprint $table) {
            $table->foreignId('visit_id')->primary()->constrained('traffic_visits')->cascadeOnDelete();
            $table->text('click_id')->nullable();
            $table->json('extra_params')->nullable();
            $table->string('landing_path', 255);
            $table->string('referrer_host', 190)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('os', 24)->nullable();
            $table->string('browser', 24)->nullable();
            $table->string('language', 12)->nullable();
            $table->dateTime('created_at');

            $table->index('created_at', 'traffic_visit_details_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_visit_details');
        Schema::dropIfExists('traffic_visits');
    }
};
