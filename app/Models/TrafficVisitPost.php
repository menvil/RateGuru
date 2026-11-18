<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post on one visit (RecordVisitPostAction): how often the visit opened
 * it, when it first shared it, whether the member voted on it or commented
 * on it there, and when the row began. A row without views is a post voted
 * or commented on from the feed without opening it. Kept 90 days from
 * first_at (traffic:purge-expired) and gone with its visit; the post is held
 * by id only, so one deleted since reads as removed.
 *
 * Keyed by its visit and its post together: a row loaded, refreshed or
 * deleted is that one visit's post, never every visit's row of the post.
 *
 * @property int $visit_id
 * @property int $post_id
 * @property int $views
 * @property CarbonImmutable|null $shared_at
 * @property bool $voted
 * @property bool $commented
 * @property CarbonImmutable $first_at
 */
class TrafficVisitPost extends Model
{
    protected $table = 'traffic_visit_posts';

    public $timestamps = false;

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visit_id' => 'integer',
            'post_id' => 'integer',
            'views' => 'integer',
            'shared_at' => 'immutable_datetime',
            'voted' => 'boolean',
            'commented' => 'boolean',
            'first_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(TrafficVisit::class, 'visit_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query): Builder
    {
        return $this->setKeysForSaveQuery($query);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query
            ->where('visit_id', '=', $this->original['visit_id'] ?? $this->getAttribute('visit_id'))
            ->where('post_id', '=', $this->original['post_id'] ?? $this->getAttribute('post_id'));
    }
}
