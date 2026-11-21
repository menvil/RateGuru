<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The people of a network day at a level of detail (AnalyticsCellLevel): each
 * step's people as a roaring bitmap of their numbers (AnalyticsPid), and the
 * visits' sums. Written by RefreshAnalyticsCellsAction, read by
 * AnalyticsCellsQuery; a bitmap is bytes to every engine but PostgreSQL with
 * the roaringbitmap extension, the only one that writes or reads them.
 *
 * @property int $id
 * @property string $level
 * @property string $network_day
 */
class AnalyticsCell extends Model
{
    protected $table = 'analytics_cells';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];
}
