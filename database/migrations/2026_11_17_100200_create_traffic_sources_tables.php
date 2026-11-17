<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where paid visitors come from: an ad network, or a banner or link
        // bought at a fixed price (docs/architecture/traffic.md). Unpaid
        // traffic has no rows here: its channel is enough. Kept for good —
        // a source is ended, never deleted. A network source carries a copy
        // of its preset's link parameters and response rules, so a later
        // change of the preset never changes it.
        Schema::create('traffic_sources', function (Blueprint $table) {
            $table->id();
            // Growing sets: TrafficSourceType, TrafficSourceStatus,
            // TrafficSpendMode, TrafficCostUnit, TrafficFixedPeriod,
            // TrafficBlockLevel.
            $table->string('type', 16);
            $table->string('name', 120);
            // The value of rg_src in the source's links.
            $table->string('link_key', 40)->unique('traffic_sources_link_key_unique');
            $table->string('preset_key', 40)->nullable();
            $table->string('connector', 24)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedTinyInteger('attribution_days')->default(7);
            $table->unsignedSmallInteger('click_id_ttl_hours')->nullable();
            $table->decimal('target_cpa', 10, 4)->nullable();
            // The time zone the network's days are counted in, so spend per
            // day matches its own reports.
            $table->string('reporting_timezone', 64)->default('UTC');
            $table->json('link_params');
            $table->json('response_rules')->nullable();
            $table->string('spend_mode', 16);
            $table->string('spend_estimate', 16)->nullable();
            $table->string('cost_unit', 8)->default('click');
            $table->string('rate_unit', 8)->default('click');
            $table->decimal('rate_mobile', 12, 6)->nullable();
            $table->decimal('rate_desktop', 12, 6)->nullable();
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->string('fixed_period', 8)->nullable();
            // Days as Y-m-d strings: compared as written on every engine.
            $table->char('fixed_from', 10)->nullable();
            $table->char('fixed_to', 10)->nullable();
            $table->string('block_level', 8)->default('site');
            $table->boolean('block_auto')->default(false);
            $table->unsignedSmallInteger('block_daily_cap')->default(10);
            $table->string('place_site', 160)->nullable();
            $table->string('place_position', 160)->nullable();
            $table->json('creatives')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index('created_by_user_id', 'traffic_sources_created_by_index');
            $table->index('updated_by_user_id', 'traffic_sources_updated_by_index');
        });

        // A source's connection to its network's API. The credentials are
        // encrypted with the application key and never leave the model.
        Schema::create('traffic_api_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->unique('traffic_api_connections_source_unique')->constrained('traffic_sources')->cascadeOnDelete();
            $table->string('connector', 24);
            $table->text('credentials')->nullable();
            $table->string('account_label', 120)->nullable();
            // A growing set (TrafficConnectionStatus).
            $table->string('status', 20);
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('next_sync_at')->nullable();
            $table->dateTime('campaigns_synced_at')->nullable();
            $table->char('spend_closed_through', 10)->nullable();
            $table->char('backfill_cursor', 10)->nullable();
            $table->string('last_error', 40)->nullable();
            $table->unsignedTinyInteger('failures')->default(0);
            $table->foreignId('connected_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_sync_at'], 'traffic_api_connections_due_index');
            $table->index('connected_by_user_id', 'traffic_api_connections_connected_by_index');
        });

        // What a network is told, and when: one address per event of the
        // visitor's way, with its own goal code.
        Schema::create('traffic_postback_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('traffic_sources')->cascadeOnDelete();
            // A growing set (TrafficEvent).
            $table->string('event', 24);
            $table->string('goal_code', 120)->nullable();
            $table->text('url_template');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['source_id', 'position'], 'traffic_postback_rules_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_postback_rules');
        Schema::dropIfExists('traffic_api_connections');
        Schema::dropIfExists('traffic_sources');
    }
};
