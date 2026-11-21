<?php

namespace App\Models;

use App\Models\Concerns\ScopesTrackedVisits;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What Growth → Analytics and a game's Analytics read of one visit
 * (analytics:refresh-visits): its day and arrival, the browser as a number,
 * each step as a flag, the sums, and the columns a split or a filter names
 * — kept beside the visit, the same id, gone with it.
 *
 * @property int $id
 * @property string $network_day
 * @property CarbonImmutable $arrived_at
 * @property CarbonImmutable $last_seen_at
 * @property int|null $entry_visit_id
 * @property string $channel
 * @property int|null $source_id
 * @property int|null $campaign_id
 * @property int $visitor_key
 * @property string|null $visitor_hash
 * @property bool $opened
 * @property bool $known
 * @property bool $signed_in
 * @property bool $started
 * @property bool $completed
 * @property bool $goal
 * @property bool $signup_form
 * @property bool $signed_up
 * @property bool $account
 * @property bool $activated
 * @property bool $post_published
 * @property bool $returned
 * @property CarbonImmutable|null $signup_at
 * @property int|null $signup_after_seconds
 * @property int|null $account_after_seconds
 * @property int $votes
 * @property int $comments
 * @property int $posts_created
 * @property string $spend
 * @property bool $spend_estimated
 * @property string $value_real
 * @property string $value_expected
 * @property bool $tagged
 * @property bool $crawler
 * @property string|null $source_key
 * @property string|null $campaign_key
 * @property string|null $creative_key
 * @property string|null $landing_key
 * @property string $device
 * @property string|null $country
 * @property string|null $city
 * @property int|null $game_id
 * @property int|null $version_id
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 */
class AnalyticsVisit extends Model
{
    use ScopesTrackedVisits;

    protected $table = 'analytics_visits';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'arrived_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'entry_visit_id' => 'integer',
            'source_id' => 'integer',
            'campaign_id' => 'integer',
            'visitor_key' => 'integer',
            'opened' => 'boolean',
            'known' => 'boolean',
            'signed_in' => 'boolean',
            'started' => 'boolean',
            'completed' => 'boolean',
            'goal' => 'boolean',
            'signup_form' => 'boolean',
            'signed_up' => 'boolean',
            'account' => 'boolean',
            'activated' => 'boolean',
            'post_published' => 'boolean',
            'returned' => 'boolean',
            'signup_at' => 'immutable_datetime',
            'signup_after_seconds' => 'integer',
            'account_after_seconds' => 'integer',
            'votes' => 'integer',
            'comments' => 'integer',
            'posts_created' => 'integer',
            'spend_estimated' => 'boolean',
            'tagged' => 'boolean',
            'crawler' => 'boolean',
            'game_id' => 'integer',
            'version_id' => 'integer',
        ];
    }

    /** @return BelongsTo<TrafficVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(TrafficVisit::class, 'id');
    }
}
