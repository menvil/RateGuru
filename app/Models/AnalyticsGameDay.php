<?php

namespace App\Models;

use App\Models\Concerns\ScopesTrackedVisits;
use Illuminate\Database\Eloquent\Model;

/**
 * The sums of a game's visits that share a network day, a UTC day of arrival
 * and one set of keys — channel, source, campaign, version, device, country
 * (none at the 'all' level), signed in or not, and an unconfirmed sign-up's
 * moment — with the first of them to arrive. Written by
 * RefreshAnalyticsGameDaysAction; read by TrafficReportQuery for a game's
 * figures over whole days, the visits of a part day beside them.
 *
 * @property int $id
 * @property string $level
 * @property string $network_day
 * @property string $arrival_day
 * @property int $game_id
 */
class AnalyticsGameDay extends Model
{
    use ScopesTrackedVisits;

    /** The level that keeps each country apart. */
    public const string BY_COUNTRY = 'country';

    /** The level that sums every country together. */
    public const string ALL = 'all';

    protected $table = 'analytics_game_days';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];
}
