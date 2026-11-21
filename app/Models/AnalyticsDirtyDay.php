<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A network day older than the ones Analytics refreshes every minute that
 * something changed since its rows were last written, with the token of the
 * latest change (MarkAnalyticsDayAction).
 *
 * @property string $day
 * @property string $token
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
}
