<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The accounts credited to a network day's visits on a device whose session
 * opened a post first — or, with no post, whose session opened none — as a
 * roaring bitmap of their browsers' numbers (AnalyticsPid). Written by
 * RefreshAnalyticsCellsAction with the day's cells, from the post each
 * credited visit's row keeps (AnalyticsVisitRow::sessionPost()); read by
 * AnalyticsContentQuery for the accounts each post brought.
 *
 * @property int $id
 * @property string $network_day
 * @property string $device
 * @property int|null $post_id
 */
class AnalyticsFirstPostCell extends Model
{
    protected $table = 'analytics_first_post_cells';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];
}
