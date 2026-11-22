<?php

namespace App\Models;

use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnector;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficApiConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A source's connection to its network's API. The credentials are encrypted
 * with the application key, hidden from every array and JSON form of the
 * model, and read only by the connector.
 *
 * @property int $id
 * @property int $source_id
 * @property TrafficConnector $connector
 * @property array<string, mixed>|null $credentials
 * @property string|null $account_label
 * @property TrafficConnectionStatus $status
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $next_sync_at
 * @property CarbonImmutable|null $campaigns_synced_at
 * @property CarbonImmutable|null $block_lists_read_at
 * @property string|null $spend_closed_through
 * @property string|null $backfill_cursor
 * @property string|null $last_error
 * @property int $failures
 * @property int $connected_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficApiConnection extends Model
{
    /** @use HasFactory<TrafficApiConnectionFactory> */
    use HasFactory;

    protected $table = 'traffic_api_connections';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'connector' => TrafficConnector::class,
            'credentials' => 'encrypted:array',
            'status' => TrafficConnectionStatus::class,
            'last_synced_at' => 'immutable_datetime',
            'next_sync_at' => 'immutable_datetime',
            'campaigns_synced_at' => 'immutable_datetime',
            'block_lists_read_at' => 'immutable_datetime',
            'failures' => 'integer',
            'connected_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }
}
