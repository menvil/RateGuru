<?php

use App\Models\TrafficApiConnection;
use Illuminate\Support\Facades\Schema;

/**
 * Why a connection's last read of its campaigns' block lists failed, and on
 * whose list: the migration adds both empty, takes them off again with its
 * down(), and the connections stay as they were either way.
 */
function blockListsErrorMigration(): object
{
    return require database_path('migrations/2026_11_22_160000_add_block_lists_error_to_traffic_api_connections_table.php');
}

it('adds an empty failure of the last read of the block lists to the connections, and takes it off again', function () {
    $connection = TrafficApiConnection::factory()->create(['account_label' => 'owner']);

    expect($connection->fresh())->block_lists_error->toBeNull()->block_lists_error_campaign->toBeNull();

    blockListsErrorMigration()->down();
    expect(Schema::hasColumn('traffic_api_connections', 'block_lists_error'))->toBeFalse()
        ->and(Schema::hasColumn('traffic_api_connections', 'block_lists_error_campaign'))->toBeFalse();

    blockListsErrorMigration()->up();
    expect(Schema::hasColumns('traffic_api_connections', ['block_lists_error', 'block_lists_error_campaign']))->toBeTrue()
        ->and(TrafficApiConnection::query()->sole())->account_label->toBe('owner')->block_lists_error->toBeNull();
});
