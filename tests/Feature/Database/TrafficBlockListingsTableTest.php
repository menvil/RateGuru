<?php

use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficBlockListing;
use App\Models\TrafficCampaign;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each block stands on each campaign's list: one row a block and
 * campaign, going with its block; whether a batch of the blocks log went
 * part of the way; and when a connection last read its campaigns' lists —
 * all alike on every engine, and taken off again by the migrations' down().
 *
 * @return array{0: object, 1: object} the table's migration, then the columns'
 */
function blockListingsMigrations(): array
{
    return [
        require database_path('migrations/2026_11_22_150000_create_traffic_block_listings_table.php'),
        require database_path('migrations/2026_11_22_150100_add_block_list_columns_to_traffic_tables.php'),
    ];
}

it('keeps one listing a block and campaign, which goes with its block', function () {
    $block = TrafficBlock::factory()->create();
    $campaign = TrafficCampaign::factory()->create(['source_id' => $block->source_id]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => true]);

    expect(fn () => DB::transaction(fn () => TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => false])))
        ->toThrow(QueryException::class);

    $block->delete();

    expect(TrafficBlockListing::query()->count())->toBe(0);
});

it('reads a batch logged before as gone all the way or not at all, and a connection as never having read its lists', function () {
    $event = TrafficBlockEvent::query()->create(['source_id' => TrafficBlock::factory()->create()->source_id, 'kind' => 'applied', 'level' => 'site', 'ref' => '1', 'ref_count' => 1, 'method' => 'api', 'actor' => 'network_api', 'ok' => false, 'occurred_at' => now()]);

    expect($event->fresh()->partial)->toBeFalse()
        ->and(TrafficApiConnection::factory()->create()->fresh()->block_lists_read_at)->toBeNull();
});

it('adds the listings and the columns, and takes them off again', function () {
    [$table, $columns] = blockListingsMigrations();

    $columns->down();
    $table->down();

    expect(Schema::hasTable('traffic_block_listings'))->toBeFalse()
        ->and(Schema::hasColumn('traffic_block_events', 'partial'))->toBeFalse()
        ->and(Schema::hasColumn('traffic_api_connections', 'block_lists_read_at'))->toBeFalse();

    $table->up();
    $columns->up();

    expect(Schema::hasTable('traffic_block_listings'))->toBeTrue()
        ->and(Schema::hasColumn('traffic_block_events', 'partial'))->toBeTrue()
        ->and(Schema::hasColumn('traffic_api_connections', 'block_lists_read_at'))->toBeTrue();
});
