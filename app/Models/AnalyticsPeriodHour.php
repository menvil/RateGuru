<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How far the visitors a traffic period sent to a version in one UTC hour
 * got: the assignments it made then, how many of them started, completed,
 * saw a result, left an email and reached the goal, and their attempts and
 * completed attempts. Written by RefreshAnalyticsPeriodHoursAction; read by
 * GameMetricsQuery::periodFunnel() for every hour counted, the assignments of
 * the hours since and of an hour changed since beside them.
 *
 * @property int $id
 * @property int $period_id
 * @property int $version_id
 * @property string $assigned_hour
 */
class AnalyticsPeriodHour extends Model
{
    protected $table = 'analytics_period_hours';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return array_fill_keys(['period_id', 'version_id', 'assigned', 'started', 'completed', 'result_viewed', 'emailed', 'goal', 'attempts', 'attempts_completed'], 'integer');
    }
}
