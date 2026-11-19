<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A site or zone of a network that is blocked — marked by hand, or put
        // on the network's blacklist through its API — and how many clicks
        // still came from it afterwards. Unblocking deletes the row; the log
        // keeps its history.
        Schema::create('traffic_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            // Growing sets: TrafficBlockLevel, TrafficBlockMethod,
            // TrafficBlockVerdict, TrafficBlockNetworkState.
            $table->string('level', 8);
            $table->string('ref', 120);
            // Null: every campaign of the source.
            $table->foreignId('campaign_id')->nullable()->constrained('traffic_campaigns')->restrictOnDelete();
            $table->string('method', 8);
            $table->string('verdict', 12)->nullable();
            $table->string('reason', 200)->nullable();
            $table->char('batch', 26)->nullable();
            $table->string('network_state', 12)->default('by_hand');
            $table->string('network_error', 40)->nullable();
            $table->unsignedInteger('clicks_after')->default(0);
            $table->dateTime('last_click_after_at')->nullable();
            $table->foreignId('blocked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('blocked_at');
            $table->timestamps();

            $table->unique(['source_id', 'level', 'ref'], 'traffic_blocks_source_level_ref_unique');
            $table->index(['network_state', 'source_id'], 'traffic_blocks_network_state_index');
            $table->index('campaign_id', 'traffic_blocks_campaign_index');
            $table->index('blocked_by_user_id', 'traffic_blocks_blocked_by_index');
        });

        // Every block and unblock, with the network's answer. Kept 400 days.
        Schema::create('traffic_block_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            $table->string('kind', 8);
            $table->string('level', 8);
            $table->string('ref', 120)->nullable();
            $table->unsignedInteger('ref_count')->default(1);
            $table->json('refs')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->string('method', 8);
            $table->string('actor', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('ok');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('note', 300)->nullable();
            $table->dateTime('occurred_at');

            $table->index(['source_id', 'id'], 'traffic_block_events_source_index');
            $table->index('occurred_at', 'traffic_block_events_occurred_index');
            $table->index('actor_user_id', 'traffic_block_events_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_block_events');
        Schema::dropIfExists('traffic_blocks');
    }
};
