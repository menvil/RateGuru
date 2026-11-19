<?php

namespace App\Models;

use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackOutcome;
use App\Enums\TrafficPostbackStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficPostbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One postback in the outbox: a conversion told to a network by one of its
 * rules, its address rendered when it was queued.
 *
 * @property int $id
 * @property int $conversion_id
 * @property int|null $rule_id
 * @property int $source_id
 * @property TrafficEvent $event
 * @property string $url
 * @property TrafficPostbackStatus $status
 * @property int $attempts
 * @property CarbonImmutable $run_after
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $http_status
 * @property int|null $latency_ms
 * @property TrafficPostbackOutcome|null $outcome
 * @property string|null $response_excerpt
 * @property CarbonImmutable|null $resent_at
 * @property CarbonImmutable $created_at
 */
class TrafficPostback extends Model
{
    /** @use HasFactory<TrafficPostbackFactory> */
    use HasFactory;

    protected $table = 'traffic_postbacks';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'conversion_id' => 'integer',
            'rule_id' => 'integer',
            'source_id' => 'integer',
            'event' => TrafficEvent::class,
            'status' => TrafficPostbackStatus::class,
            'attempts' => 'integer',
            'run_after' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'outcome' => TrafficPostbackOutcome::class,
            'resent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficConversion, $this> */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(TrafficConversion::class, 'conversion_id');
    }

    /** @return BelongsTo<TrafficPostbackRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(TrafficPostbackRule::class, 'rule_id');
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }

    /** @return HasMany<TrafficPostbackAttempt, $this> */
    public function failedAttempts(): HasMany
    {
        return $this->hasMany(TrafficPostbackAttempt::class, 'postback_id')->orderBy('attempt');
    }
}
