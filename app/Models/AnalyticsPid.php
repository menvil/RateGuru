<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The short number a browser whose page ran is counted by in Analytics' cells
 * (AnalyticsCell): given once, kept while any of its visits is.
 *
 * @property int $visitor_key
 * @property int $pid
 */
class AnalyticsPid extends Model
{
    protected $table = 'analytics_pids';

    protected $primaryKey = 'visitor_key';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visitor_key' => 'integer', 'pid' => 'integer'];
    }
}
