<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Its foreign keys lock traffic_blocks and traffic_campaigns while
        // they are made: waited for briefly, never behind a long write.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        // Where a block stands on each campaign's list, as RateGuru last wrote
        // or read that list (docs/architecture/traffic.md, "Blocking"): on it
        // or not, and why — the network refused it, or it was taken off in
        // the network's panel; on a list still holding it, why taking it off
        // failed. A block on some campaigns' lists and not others is partly
        // blocked; an unblock takes it off the campaigns whose lists hold it.
        // Goes with its block, and with its campaign.
        Schema::create('traffic_block_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained('traffic_blocks')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('traffic_campaigns')->cascadeOnDelete();
            $table->boolean('listed');
            // A TrafficConnectorFailure, or taken_off (TrafficBlockListing::TAKEN_OFF).
            $table->string('error', 40)->nullable();
            $table->dateTime('checked_at');

            $table->unique(['block_id', 'campaign_id'], 'traffic_block_listings_block_campaign_unique');
            $table->index('campaign_id', 'traffic_block_listings_campaign_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_block_listings');
    }
};
