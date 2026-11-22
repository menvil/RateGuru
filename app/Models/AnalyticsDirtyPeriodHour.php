<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A UTC hour of assignments already counted (AnalyticsPeriodHour) that
 * something changed an assignment of since — an attempt started or completed,
 * a result seen, an email left, a goal reached, an account confirmed or
 * activated, a row purged — with the token of the latest change and the
 * moment it was first marked (MarkAnalyticsPeriodHourAction).
 *
 * @property CarbonImmutable $assigned_hour
 * @property string $token
 * @property CarbonImmutable $marked_at
 */
class AnalyticsDirtyPeriodHour extends Model
{
    protected $table = 'analytics_dirty_period_hours';

    protected $primaryKey = 'assigned_hour';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assigned_hour' => 'immutable_datetime',
            'marked_at' => 'immutable_datetime',
        ];
    }
}
