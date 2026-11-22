<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The accounts of a cell (AnalyticsCell) by the UTC day they signed up on, as
 * a roaring bitmap of their numbers: whether an account's days to activate
 * are over by today.
 *
 * @property int $id
 * @property string $level
 * @property string $network_day
 * @property string $signup_day
 */
class AnalyticsCellAccount extends Model
{
    protected $table = 'analytics_cell_accounts';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];
}
