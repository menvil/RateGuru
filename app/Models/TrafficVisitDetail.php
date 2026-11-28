<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The rest of a click, kept 90 days: the network's click id itself, the other
 * link parameters, the landing path, the referrer and a keyed hash of the
 * address — never the address.
 *
 * @property int $id
 * @property int $visit_id
 * @property string|null $click_id
 * @property array<string, string>|null $extra_params
 * @property string $landing_path
 * @property string|null $referrer_host
 * @property string|null $ip_hash
 * @property string|null $os
 * @property string|null $browser
 * @property string|null $language
 * @property array<string, mixed>|null $presence
 * @property CarbonImmutable $created_at
 */
class TrafficVisitDetail extends Model
{
    protected $table = 'traffic_visit_details';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visit_id' => 'integer',
            'extra_params' => 'array',
            'presence' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected $primaryKey = 'visit_id';

    public $incrementing = false;

    /** @return BelongsTo<TrafficVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(TrafficVisit::class, 'visit_id');
    }
}
