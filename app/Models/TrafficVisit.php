<?php

namespace App\Models;

use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficDimension;
use App\Enums\TrafficEvent;
use App\Enums\TrafficKnownReason;
use App\Enums\TrafficSpendBasis;
use App\Support\Traffic\Reports\VisitFilter;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficVisitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One visit: a paid click, an unpaid arrival on a game, or — while Record site
 * visits is on — an unpaid visit to the site's other pages. Every report is a
 * sum over these rows, so each carries its dimensions, the moments it reached,
 * what it cost and what it is worth (docs/architecture/traffic.md) — and the
 * visit its session began with, with where that one came from and the
 * campaign tags it came by (`utm_*`), and the new votes, comments and posts
 * a member made on it; the posts it opened and shared are its rows in
 * `traffic_visit_posts`. The tracker itself counts only the visits it
 * tracks: paid clicks and game visits.
 *
 * @property int $id
 * @property TrafficChannel $channel
 * @property int|null $source_id
 * @property int|null $campaign_id
 * @property string|null $site_ref
 * @property string|null $zone_ref
 * @property string|null $creative_ref
 * @property string|null $origin
 * @property string|null $click_hash
 * @property string $visitor_hash
 * @property bool $known
 * @property TrafficKnownReason|null $known_reason
 * @property TrafficDevice $device
 * @property string|null $country
 * @property string|null $region
 * @property string|null $city
 * @property int|null $game_id
 * @property int|null $version_id
 * @property int $price_set_id
 * @property string $country_group
 * @property int $worth_percent
 * @property int $bot_flags
 * @property bool $blocked_hit
 * @property CarbonImmutable $arrived_at
 * @property string $network_day
 * @property CarbonImmutable|null $holds_until
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $halfway_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $goal_at
 * @property CarbonImmutable|null $email_at
 * @property CarbonImmutable|null $shared_at
 * @property CarbonImmutable|null $replayed_at
 * @property CarbonImmutable|null $post_opened_at
 * @property CarbonImmutable|null $post_voted_at
 * @property CarbonImmutable|null $post_rated_at
 * @property CarbonImmutable|null $feed_voted_at
 * @property CarbonImmutable|null $next_game_at
 * @property CarbonImmutable|null $page_at
 * @property CarbonImmutable|null $feed_at
 * @property CarbonImmutable|null $signup_form_at
 * @property CarbonImmutable|null $signup_at
 * @property CarbonImmutable|null $account_at
 * @property CarbonImmutable|null $login_at
 * @property CarbonImmutable|null $post_published_at
 * @property CarbonImmutable|null $returned_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $late_signup_at
 * @property AccountSignupPlace|null $signup_place
 * @property AccountSignupMethod|null $signup_method
 * @property int|null $signup_after_seconds
 * @property int|null $account_after_seconds
 * @property int $rounds_answered
 * @property string|null $cost
 * @property string $spend
 * @property TrafficSpendBasis $spend_basis
 * @property bool $spend_estimated
 * @property string $value_real
 * @property string $value_expected
 * @property int|null $entry_visit_id
 * @property string|null $source_key
 * @property string|null $campaign_key
 * @property string|null $creative_key
 * @property string|null $landing_key
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 * @property int $votes
 * @property int $comments
 * @property int $posts_created
 */
class TrafficVisit extends Model
{
    /** @use HasFactory<TrafficVisitFactory> */
    use HasFactory;

    protected $table = 'traffic_visits';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => TrafficChannel::class,
            'source_id' => 'integer',
            'campaign_id' => 'integer',
            'known' => 'boolean',
            'known_reason' => TrafficKnownReason::class,
            'device' => TrafficDevice::class,
            'game_id' => 'integer',
            'version_id' => 'integer',
            'entry_visit_id' => 'integer',
            'price_set_id' => 'integer',
            'worth_percent' => 'integer',
            'bot_flags' => 'integer',
            'blocked_hit' => 'boolean',
            'arrived_at' => 'immutable_datetime',
            'holds_until' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'halfway_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'goal_at' => 'immutable_datetime',
            'email_at' => 'immutable_datetime',
            'shared_at' => 'immutable_datetime',
            'replayed_at' => 'immutable_datetime',
            'post_opened_at' => 'immutable_datetime',
            'post_voted_at' => 'immutable_datetime',
            'post_rated_at' => 'immutable_datetime',
            'feed_voted_at' => 'immutable_datetime',
            'next_game_at' => 'immutable_datetime',
            'page_at' => 'immutable_datetime',
            'feed_at' => 'immutable_datetime',
            'signup_form_at' => 'immutable_datetime',
            'signup_at' => 'immutable_datetime',
            'account_at' => 'immutable_datetime',
            'login_at' => 'immutable_datetime',
            'post_published_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'late_signup_at' => 'immutable_datetime',
            'signup_place' => AccountSignupPlace::class,
            'signup_method' => AccountSignupMethod::class,
            'signup_after_seconds' => 'integer',
            'account_after_seconds' => 'integer',
            'rounds_answered' => 'integer',
            'votes' => 'integer',
            'comments' => 'integer',
            'posts_created' => 'integer',
            'cost' => 'decimal:8',
            'spend' => 'decimal:8',
            'spend_basis' => TrafficSpendBasis::class,
            'spend_estimated' => 'boolean',
            'value_real' => 'decimal:8',
            'value_expected' => 'decimal:8',
        ];
    }

    public function isPaid(): bool
    {
        return $this->channel->isPaid();
    }

    public function reached(TrafficEvent $event): bool
    {
        return $this->getAttribute($event->column()) !== null;
    }

    /**
     * Whether the tracker counts the visit: a paid click, or an unpaid visit
     * to a game. An unpaid visit to the rest of the site is worth nothing and
     * stays out of Traffic sources, the chances and the revaluation.
     */
    public function isTracked(): bool
    {
        return $this->game_id !== null || $this->source_id !== null;
    }

    /**
     * The visits the tracker counts, as isTracked() tells them: every read
     * behind Traffic sources, a game's Analytics and the worth goes through it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTracked(Builder $query): Builder
    {
        $game = $query->qualifyColumn('game_id');
        $source = $query->qualifyColumn('source_id');

        return $query->where(fn (Builder $either) => $either->whereNotNull($game)->orWhereNotNull($source));
    }

    /**
     * The visits a filter admits by their device and country, the columns
     * named by TrafficDimension: the price set's last group takes a visit
     * whose country is not known.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMatching(Builder $query, VisitFilter $filter): Builder
    {
        $device = $query->qualifyColumn(TrafficDimension::Device->columns()[0]);
        $country = $query->qualifyColumn(TrafficDimension::Country->columns()[0]);
        $countries = $filter->countries;

        return $query
            ->when($filter->device !== null, fn (Builder $inner) => $inner->where($device, $filter->device))
            ->when($countries !== null && ! $filter->otherCountries, fn (Builder $inner) => $inner->whereIn($country, $countries ?? []))
            ->when($countries !== null && $filter->otherCountries, fn (Builder $inner) => $inner->where(fn (Builder $either) => $either->whereNull($country)->orWhereNotIn($country, $countries ?? [])));
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }

    /** @return BelongsTo<TrafficCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrafficCampaign::class, 'campaign_id');
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /** @return BelongsTo<GameVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class, 'version_id');
    }

    /** @return BelongsTo<TrafficPriceSet, $this> */
    public function priceSet(): BelongsTo
    {
        return $this->belongsTo(TrafficPriceSet::class, 'price_set_id');
    }

    /** @return HasOne<TrafficVisitDetail, $this> */
    public function detail(): HasOne
    {
        return $this->hasOne(TrafficVisitDetail::class, 'visit_id');
    }

    /** @return HasMany<TrafficConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(TrafficConversion::class, 'visit_id');
    }

    /** @return HasMany<TrafficVisitPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(TrafficVisitPost::class, 'visit_id');
    }
}
