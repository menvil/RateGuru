<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A post's people on a network day and device: those who opened it, of them
 * those who voted on it or commented on it, and those who voted on it, as
 * roaring bitmaps of their numbers (AnalyticsPid), with its views, votes and
 * comments. Written by RefreshAnalyticsCellsAction with the day's cells, read
 * by AnalyticsContentQuery for the posts opened most.
 *
 * @property int $id
 * @property string $network_day
 * @property string $device
 * @property int $post_id
 */
class AnalyticsPostCell extends Model
{
    protected $table = 'analytics_post_cells';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];
}
