<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A browser whose page ran, by the number Analytics knows it by
 * (AnalyticsVisit::$visitor_key), and the first network day it did: whether
 * a visitor of a period had come before it.
 *
 * @property int $visitor_key
 * @property string $first_day
 */
class AnalyticsPerson extends Model
{
    protected $table = 'analytics_people';

    protected $primaryKey = 'visitor_key';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visitor_key' => 'integer'];
    }
}
