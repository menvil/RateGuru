<?php

namespace App\Models;

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficConnector;
use App\Enums\TrafficCostUnit;
use App\Enums\TrafficFixedPeriod;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendMode;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A paid source: an ad network created from a preset, or a banner or link
 * bought at a fixed price (docs/architecture/traffic.md). It keeps a copy of
 * its preset's link parameters and response rules, so a later change of the
 * preset never changes it.
 *
 * @property int $id
 * @property TrafficSourceType $type
 * @property string $name
 * @property string $link_key
 * @property string|null $preset_key
 * @property TrafficConnector|null $connector
 * @property TrafficSourceStatus $status
 * @property int $attribution_days
 * @property int|null $click_id_ttl_hours
 * @property string|null $target_cpa
 * @property string $reporting_timezone
 * @property list<array{kind: string, label: string, param: string, token: string, note: string}> $link_params
 * @property array{rules: list<array{outcome: string, match: string, text: string}>, otherwise: string}|null $response_rules
 * @property TrafficSpendMode $spend_mode
 * @property string|null $spend_estimate
 * @property TrafficCostUnit $cost_unit
 * @property TrafficCostUnit $rate_unit
 * @property string|null $rate_mobile
 * @property string|null $rate_desktop
 * @property string|null $fixed_amount
 * @property TrafficFixedPeriod|null $fixed_period
 * @property string|null $fixed_from
 * @property string|null $fixed_to
 * @property TrafficBlockLevel $block_level
 * @property bool $block_auto
 * @property int $block_daily_cap
 * @property string|null $place_site
 * @property string|null $place_position
 * @property list<string>|null $creatives
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficSource extends Model
{
    /** @use HasFactory<TrafficSourceFactory> */
    use HasFactory;

    protected $table = 'traffic_sources';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TrafficSourceType::class,
            'connector' => TrafficConnector::class,
            'status' => TrafficSourceStatus::class,
            'attribution_days' => 'integer',
            'click_id_ttl_hours' => 'integer',
            'target_cpa' => 'decimal:4',
            'link_params' => 'array',
            'response_rules' => 'array',
            'spend_mode' => TrafficSpendMode::class,
            'cost_unit' => TrafficCostUnit::class,
            'rate_unit' => TrafficCostUnit::class,
            'rate_mobile' => 'decimal:6',
            'rate_desktop' => 'decimal:6',
            'fixed_amount' => 'decimal:2',
            'fixed_period' => TrafficFixedPeriod::class,
            'block_level' => TrafficBlockLevel::class,
            'block_auto' => 'boolean',
            'block_daily_cap' => 'integer',
            'creatives' => 'array',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isNetwork(): bool
    {
        return $this->type === TrafficSourceType::Network;
    }

    public function isActive(): bool
    {
        return $this->status === TrafficSourceStatus::Active;
    }

    /** @return HasOne<TrafficApiConnection, $this> */
    public function apiConnection(): HasOne
    {
        return $this->hasOne(TrafficApiConnection::class, 'source_id');
    }

    /** @return HasMany<TrafficPostbackRule, $this> */
    public function postbackRules(): HasMany
    {
        return $this->hasMany(TrafficPostbackRule::class, 'source_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<TrafficCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(TrafficCampaign::class, 'source_id');
    }

    /** @return HasMany<TrafficBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(TrafficBlock::class, 'source_id');
    }
}
