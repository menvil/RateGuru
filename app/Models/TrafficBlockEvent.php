<?php

namespace App\Models;

use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One block or unblock, with the network's answer: done all the way (ok),
 * part of the way (partial) or not at all; or what a read of the network's
 * lists found blocked in its panel (`found`), or taken off there (`gone`) —
 * off every list it was on, or, partial, off some only. A line the read
 * wrote names every site or zone it is about in `refs`.
 *
 * @property int $id
 * @property int $source_id
 * @property string $kind
 * @property TrafficBlockLevel $level
 * @property string|null $ref
 * @property int $ref_count
 * @property list<string>|null $refs
 * @property int|null $campaign_id
 * @property TrafficBlockMethod $method
 * @property TrafficActor $actor
 * @property int|null $actor_user_id
 * @property bool $ok
 * @property bool $partial
 * @property int|null $http_status
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 */
class TrafficBlockEvent extends Model
{
    protected $table = 'traffic_block_events';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'level' => TrafficBlockLevel::class,
            'ref_count' => 'integer',
            'refs' => 'array',
            'campaign_id' => 'integer',
            'method' => TrafficBlockMethod::class,
            'actor' => TrafficActor::class,
            'actor_user_id' => 'integer',
            'ok' => 'boolean',
            'partial' => 'boolean',
            'http_status' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }
}
