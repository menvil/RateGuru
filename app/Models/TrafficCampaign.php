<?php

namespace App\Models;

use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficSpendMode;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficCampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A network's campaign, known by its id in the network: added by the first
 * click that carries an id not seen before, set up by the administrator or
 * read from the network's API. Archived, never deleted.
 *
 * @property int $id
 * @property int $source_id
 * @property string $external_id
 * @property string|null $name
 * @property int|null $game_id
 * @property TrafficCampaignStatus $status
 * @property TrafficCampaignStatus|null $status_before_archive
 * @property TrafficCampaignStage|null $stage
 * @property string|null $format
 * @property list<string>|null $countries
 * @property list<string>|null $devices
 * @property string|null $bid_model
 * @property string|null $bid_mobile
 * @property string|null $bid_desktop
 * @property string|null $daily_budget
 * @property TrafficSpendMode|null $spend_mode
 * @property string|null $target_cpa
 * @property string|null $whitelist_name
 * @property CarbonImmutable $first_click_at
 * @property CarbonImmutable|null $set_up_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $archived_at
 * @property int|null $archived_by_user_id
 * @property bool $archived_automatically
 * @property array<string, mixed>|null $archived_totals
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficCampaign extends Model
{
    /** @use HasFactory<TrafficCampaignFactory> */
    use HasFactory;

    protected $table = 'traffic_campaigns';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'game_id' => 'integer',
            'status' => TrafficCampaignStatus::class,
            'status_before_archive' => TrafficCampaignStatus::class,
            'stage' => TrafficCampaignStage::class,
            'countries' => 'array',
            'devices' => 'array',
            'bid_mobile' => 'decimal:6',
            'bid_desktop' => 'decimal:6',
            'daily_budget' => 'decimal:2',
            'spend_mode' => TrafficSpendMode::class,
            'target_cpa' => 'decimal:4',
            'first_click_at' => 'immutable_datetime',
            'set_up_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'archived_by_user_id' => 'integer',
            'archived_automatically' => 'boolean',
            'archived_totals' => 'array',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The target cost per account in force: the campaign's own, else its source's. */
    public function targetCpa(): ?string
    {
        return $this->target_cpa ?? $this->source->target_cpa;
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /** @return HasMany<TrafficCampaignStagePeriod, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(TrafficCampaignStagePeriod::class, 'campaign_id')->orderBy('started_at')->orderBy('id');
    }

    /** @return HasMany<TrafficCampaignEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TrafficCampaignEvent::class, 'campaign_id')->orderByDesc('id');
    }

    /** @return HasMany<TrafficCampaignPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(TrafficCampaignPrice::class, 'campaign_id');
    }
}
