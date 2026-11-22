<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The confirmed accounts credited to the attempts of the assignments a
 * traffic period made to a version in one UTC hour, that signed up on one UTC
 * day, and of them the activated: what a period's matured and activated
 * accounts are summed from, the day deciding whether an account's days to
 * activate are over. Written with the hour (AnalyticsPeriodHour).
 *
 * @property int $id
 * @property int $period_id
 * @property int $version_id
 * @property string $assigned_hour
 * @property string $signup_day
 */
class AnalyticsPeriodHourAccount extends Model
{
    protected $table = 'analytics_period_hour_accounts';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return array_fill_keys(['period_id', 'version_id', 'accounts', 'activated'], 'integer');
    }
}
