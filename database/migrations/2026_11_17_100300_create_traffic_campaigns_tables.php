<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A network's campaign, known by its id in the network: added by the
        // first click that carries an id RateGuru has not seen, set up by the
        // administrator or read from the network's API. Kept for good —
        // archived, never deleted.
        Schema::create('traffic_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            $table->string('external_id', 120);
            $table->string('name', 160)->nullable();
            $table->foreignId('game_id')->nullable()->constrained('games')->restrictOnDelete();
            // Growing sets: TrafficCampaignStatus, TrafficCampaignStage.
            $table->string('status', 16)->default('new');
            $table->string('status_before_archive', 16)->nullable();
            $table->string('stage', 16)->nullable();
            $table->string('format', 40)->nullable();
            $table->json('countries')->nullable();
            $table->json('devices')->nullable();
            $table->string('bid_model', 8)->nullable();
            $table->decimal('bid_mobile', 12, 6)->nullable();
            $table->decimal('bid_desktop', 12, 6)->nullable();
            $table->decimal('daily_budget', 12, 2)->nullable();
            // Null: counted the way the source counts it.
            $table->string('spend_mode', 16)->nullable();
            $table->decimal('target_cpa', 10, 4)->nullable();
            $table->string('whitelist_name', 120)->nullable();
            $table->dateTime('first_click_at');
            $table->dateTime('set_up_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('archived_automatically')->default(false);
            // What the campaign brought while it ran, kept once its visits
            // are past their retention.
            $table->json('archived_totals')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['source_id', 'external_id'], 'traffic_campaigns_source_external_unique');
            $table->index(['source_id', 'status'], 'traffic_campaigns_source_status_index');
            $table->index('game_id', 'traffic_campaigns_game_index');
            $table->index('archived_by_user_id', 'traffic_campaigns_archived_by_index');
        });

        // The stages a campaign went through, so reports can split its
        // results by them.
        Schema::create('traffic_campaign_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('traffic_campaigns')->cascadeOnDelete();
            $table->string('stage', 16);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();

            $table->index(['campaign_id', 'started_at'], 'traffic_campaign_stages_campaign_index');
        });

        // A campaign's history: who changed what, the network's API included.
        Schema::create('traffic_campaign_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('traffic_campaigns')->cascadeOnDelete();
            // Growing sets: TrafficCampaignEventKind, TrafficActor.
            $table->string('kind', 16);
            $table->string('summary', 300);
            $table->string('actor', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');

            $table->index(['campaign_id', 'id'], 'traffic_campaign_events_campaign_index');
            $table->index('actor_user_id', 'traffic_campaign_events_actor_index');
        });

        // A campaign's price per device from a moment on: a visit is counted
        // at the price it had when it came, so a change never recounts the
        // past.
        Schema::create('traffic_campaign_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('traffic_campaigns')->cascadeOnDelete();
            $table->string('device', 8);
            $table->string('bid_model', 8);
            $table->decimal('price', 12, 6);
            $table->dateTime('effective_from');
            $table->string('actor', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->index(['campaign_id', 'device', 'effective_from'], 'traffic_campaign_prices_effective_index');
            $table->index('actor_user_id', 'traffic_campaign_prices_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_campaign_prices');
        Schema::dropIfExists('traffic_campaign_events');
        Schema::dropIfExists('traffic_campaign_stages');
        Schema::dropIfExists('traffic_campaigns');
    }
};
