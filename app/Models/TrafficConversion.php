<?php

namespace App\Models;

use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackSkip;
use App\Enums\TrafficValueBasis;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficConversionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A moment a paid visit reached, once per visit, with what it was worth then —
 * the value its postbacks carry — or why no postback went for it.
 *
 * @property int $id
 * @property int $visit_id
 * @property TrafficEvent $event
 * @property CarbonImmutable $occurred_at
 * @property int $source_id
 * @property int|null $game_id
 * @property int|null $version_id
 * @property int|null $attempt_id
 * @property string|null $step
 * @property string|null $goal
 * @property string $value
 * @property TrafficValueBasis $value_basis
 * @property TrafficPostbackSkip|null $postback_skip
 */
class TrafficConversion extends Model
{
    /** @use HasFactory<TrafficConversionFactory> */
    use HasFactory;

    protected $table = 'traffic_conversions';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visit_id' => 'integer',
            'event' => TrafficEvent::class,
            'occurred_at' => 'immutable_datetime',
            'source_id' => 'integer',
            'game_id' => 'integer',
            'version_id' => 'integer',
            'attempt_id' => 'integer',
            'value' => 'decimal:8',
            'value_basis' => TrafficValueBasis::class,
            'postback_skip' => TrafficPostbackSkip::class,
        ];
    }

    /** @return BelongsTo<TrafficVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(TrafficVisit::class, 'visit_id');
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }

    /** @return BelongsTo<GameAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(GameAttempt::class, 'attempt_id');
    }

    /** @return HasMany<TrafficPostback, $this> */
    public function postbacks(): HasMany
    {
        return $this->hasMany(TrafficPostback::class, 'conversion_id');
    }
}
