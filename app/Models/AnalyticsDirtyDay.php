<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A network day older than the ones Analytics refreshes every minute that
 * something changed since its rows were last written, with the token of the
 * latest change and the moment it was first marked (MarkAnalyticsDayAction).
 *
 * @property string $day
 * @property string $token
 * @property CarbonImmutable $marked_at
 */
class AnalyticsDirtyDay extends Model
{
    protected $table = 'analytics_dirty_days';

    protected $primaryKey = 'day';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['marked_at' => 'immutable_datetime'];
    }
}
