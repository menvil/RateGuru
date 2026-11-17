<?php

namespace App\Models;

use App\Enums\GeoEdition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The tracker's own settings, one row: the block rule, the IP database
 * edition, and the switches that start recording visits and sending postbacks.
 *
 * @property int $id
 * @property string $block_min_spend
 * @property int $block_min_clicks
 * @property int $block_roi_below
 * @property int $block_open_below
 * @property int $block_bot_clicks
 * @property int $block_start_clicks
 * @property int $block_start_percent
 * @property GeoEdition $geo_edition
 * @property CarbonImmutable|null $geo_refresh_requested_at
 * @property bool $capture_enabled
 * @property bool $postbacks_enabled
 * @property int|null $updated_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficSetting extends Model
{
    protected $table = 'traffic_settings';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'block_min_spend' => 'decimal:2',
            'block_min_clicks' => 'integer',
            'block_roi_below' => 'integer',
            'block_open_below' => 'integer',
            'block_bot_clicks' => 'integer',
            'block_start_clicks' => 'integer',
            'block_start_percent' => 'integer',
            'geo_edition' => GeoEdition::class,
            'geo_refresh_requested_at' => 'immutable_datetime',
            'capture_enabled' => 'boolean',
            'postbacks_enabled' => 'boolean',
            'updated_by_user_id' => 'integer',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The row, or the defaults while nobody has saved one; never written here. */
    public static function current(): self
    {
        return self::query()->find(1) ?? (new self)->forceFill(['id' => 1])->setRawAttributes([
            'id' => 1,
            'block_min_spend' => '2.00',
            'block_min_clicks' => 200,
            'block_roi_below' => -40,
            'block_open_below' => 50,
            'block_bot_clicks' => 30,
            'block_start_clicks' => 60,
            'block_start_percent' => 60,
            'geo_edition' => GeoEdition::GeoLite2City->value,
            'geo_refresh_requested_at' => null,
            'capture_enabled' => false,
            'postbacks_enabled' => false,
            'updated_by_user_id' => null,
            'lock_version' => 1,
        ]);
    }
}
