<?php

namespace App\Models;

use App\Enums\TrafficPostbackOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A failed try of a postback.
 *
 * @property int $id
 * @property int $postback_id
 * @property int $attempt
 * @property CarbonImmutable $attempted_at
 * @property int|null $http_status
 * @property int|null $latency_ms
 * @property TrafficPostbackOutcome $outcome
 * @property string|null $response_excerpt
 */
class TrafficPostbackAttempt extends Model
{
    protected $table = 'traffic_postback_attempts';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'postback_id' => 'integer',
            'attempt' => 'integer',
            'attempted_at' => 'immutable_datetime',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'outcome' => TrafficPostbackOutcome::class,
        ];
    }

    /** @return BelongsTo<TrafficPostback, $this> */
    public function postback(): BelongsTo
    {
        return $this->belongsTo(TrafficPostback::class, 'postback_id');
    }
}
