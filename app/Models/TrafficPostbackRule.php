<?php

namespace App\Models;

use App\Enums\TrafficEvent;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficPostbackRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a network is told, and when: the address to call for one event of the
 * visitor's way, with its goal code.
 *
 * @property int $id
 * @property int $source_id
 * @property TrafficEvent $event
 * @property string|null $goal_code
 * @property TrafficEvent|null $needs_event
 * @property string $url_template
 * @property bool $enabled
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficPostbackRule extends Model
{
    /** @use HasFactory<TrafficPostbackRuleFactory> */
    use HasFactory;

    protected $table = 'traffic_postback_rules';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'event' => TrafficEvent::class,
            'needs_event' => TrafficEvent::class,
            'enabled' => 'boolean',
            'position' => 'integer',
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
