<?php

namespace App\Actions\Traffic;

use App\Actions\Analytics\MarkAnalyticsDayAction;
use App\Actions\Traffic\Concerns\WorksOutClickSpend;
use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficKnownReason;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendBasis;
use App\Models\Game;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignEvent;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\LandingRoute;
use App\Support\Analytics\UtmTags;
use App\Support\Observability\DomainLogger;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\ClickParameters;
use App\Support\Traffic\Capture\VisitorSignals;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Records a paid click when the page it lands on is asked for, before
 * anything is drawn — robots included, whose clicks were paid for too
 * (docs/architecture/traffic.md, "Capturing a click").
 *
 * One visit per network click id: a repeated request of the same click from
 * the same browser only touches the visit, and one whose page no browser has
 * run yet passes to the browser that runs it (a prefetch or the network's
 * own checker came first); a click id a browser already opened is no click
 * for another browser. A click without an id — a banner — is the same visit
 * again within 30 minutes. A campaign id not seen before adds the campaign,
 * and a click of an archived campaign brings it back. Floods from one
 * address are marked, and past a limit not recorded at all. A click is the
 * visit its session begins with, and keeps where it came from: its source,
 * campaign, creative and the page it landed on — by the source's own link,
 * whatever campaign tags its address carries too, which it keeps beside them.
 */
final class RecordPaidClickAction
{
    use WorksOutClickSpend;

    private const int BURST_PER_MINUTE = 60;

    private const int DROP_PER_MINUTE = 600;

    private const int SOURCE_DROP_PER_MINUTE = 3000;

    private const int NEW_CAMPAIGNS_PER_DAY = 50;

    public function __construct(
        private readonly VisitorVisitQuery $visits,
        private readonly DomainLogger $logger,
        private readonly MarkAnalyticsDayAction $analytics,
    ) {}

    /**
     * The visit the click is, or null when it is no click for this browser,
     * or one too many to record.
     *
     * @param  array<array-key, mixed>  $query
     * @param  string|null  $route  the name of the route the click landed on
     * @param  string|null  $gameSlug  the game the page belongs to, on a game's page or one of its result pages
     */
    public function handle(
        TrafficSource $source,
        array $query,
        string $browserHash,
        bool $browserIsNew,
        ?int $userId,
        ?string $route,
        ?string $gameSlug,
        VisitorSignals $signals,
        CarbonImmutable $now,
    ): ?TrafficVisit {
        $params = ClickParameters::parse($query, $source->link_params);
        $flags = $signals->botFlags;

        if (! $this->withinLimits($source, $signals->ipHash, $flags)) {
            return null;
        }

        $clickHash = $source->type === TrafficSourceType::Network && $params->clickId !== null ? hash('sha256', $params->clickId) : null;

        if ($clickHash === null) {
            $recent = TrafficVisit::query()
                ->where('visitor_hash', $browserHash)
                ->where('source_id', $source->id)
                ->whereNull('click_hash')
                ->where('creative_ref', $params->creative)
                ->where('last_seen_at', '>=', $now->subMinutes(30))
                ->orderByDesc('id')
                ->first();

            if ($recent !== null && $recent->campaign_id === $this->campaignId($source, $params, $now, false)) {
                $recent->forceFill(['last_seen_at' => $now])->save();

                return $recent;
            }
        }

        $known = $userId !== null ? TrafficKnownReason::SignedIn : (! $browserIsNew && $this->visits->isKnownBrowser($browserHash) ? TrafficKnownReason::Browser : null);
        $prices = TrafficPriceSet::current();
        $group = $prices->groupOf($signals->location->countryCode);
        $landingGameId = $gameSlug === null ? null : Game::query()->where('slug', $gameSlug)->value('id');
        $landingGameId = $landingGameId === null ? null : (int) $landingGameId;
        // The game played is the game of its own page; a result page only names it.
        $gameId = $route === 'games.play' ? $landingGameId : null;
        $cost = $params->cost === null ? null : TrafficMoney::of($params->cost)->dividedBy($source->cost_unit->divisor(), TrafficMoney::SCALE, RoundingMode::HalfUp);

        if ($cost !== null && $cost->isGreaterThan(10)) {
            $cost = null;
        }

        $channel = $source->type === TrafficSourceType::Network ? TrafficChannel::Network : TrafficChannel::Placement;
        $attributes = [
            'channel' => $channel,
            'source_id' => $source->id,
            'site_ref' => $params->site,
            'zone_ref' => $params->zone,
            'creative_ref' => $params->creative,
            'click_hash' => $clickHash,
            'visitor_hash' => $browserHash,
            'known' => $known !== null,
            'known_reason' => $known,
            'device' => $signals->device,
            'country' => $signals->location->countryCode,
            'region' => $signals->location->region,
            'city' => $signals->location->city,
            'game_id' => $gameId,
            'price_set_id' => $prices->id,
            'country_group' => $group['key'],
            'worth_percent' => $group['percent'],
            'bot_flags' => $flags,
            'arrived_at' => $now,
            'network_day' => $now->setTimezone($source->reporting_timezone)->format('Y-m-d'),
            'holds_until' => $now->addDays($source->attribution_days),
            'last_seen_at' => $now,
            'cost' => $cost === null ? null : (string) $cost,
            'spend' => '0',
            'spend_basis' => TrafficSpendBasis::None,
            'source_key' => AnalyticsSource::keyFor($channel, (int) $source->id, null),
            'creative_key' => $params->creative,
            'landing_key' => LandingRoute::key($route, $landingGameId, null),
            ...UtmTags::fromQuery($query)->columns(),
        ];

        return DB::transaction(function () use ($source, $params, $attributes, $clickHash, $browserHash, $signals, $now): ?TrafficVisit {
            $attributes['campaign_id'] = $this->campaignId($source, $params, $now, true);
            $attributes['campaign_key'] = CampaignKey::paid($attributes['campaign_id']);
            $attributes = [...$attributes, ...$this->clickSpend($source, $attributes['campaign_id'] === null ? null : TrafficCampaign::query()->find($attributes['campaign_id']), $attributes['cost'], $signals->device, $now)];

            try {
                $visit = DB::transaction(function () use ($attributes): TrafficVisit {
                    $visit = new TrafficVisit;
                    $visit->forceFill($attributes)->save();
                    // A paid click always begins a session of its own.
                    $visit->forceFill(['entry_visit_id' => $visit->id])->save();

                    return $visit;
                });
            } catch (UniqueConstraintViolationException) {
                return $this->repeated($source, (string) $clickHash, $browserHash, $attributes, $now);
            }

            TrafficVisitDetail::query()->forceCreate([
                'visit_id' => $visit->id,
                'click_id' => $params->clickId,
                'extra_params' => $params->extra === [] ? null : $params->extra,
                'landing_path' => $signals->path,
                'referrer_host' => $signals->referrerHost,
                'ip_hash' => $signals->ipHash,
                'os' => $signals->os,
                'browser' => $signals->browser,
                'language' => $signals->language,
                'created_at' => $now,
            ]);

            app(RecordBlockedHitAction::class)->handle($visit);

            return $visit;
        });
    }

    /**
     * The same click asked for again: by the same browser, it is the same
     * visit; by another browser, it passes to that browser while no browser
     * has run its page — priced for that browser's device, unless its spend
     * was taken from a day's figures — and is no click for it once one has.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function repeated(TrafficSource $source, string $clickHash, string $browserHash, array $attributes, CarbonImmutable $now): ?TrafficVisit
    {
        $visit = TrafficVisit::query()->where('source_id', $source->id)->where('click_hash', $clickHash)->lockForUpdate()->first();

        if ($visit === null) {
            return null;
        }

        if ($visit->visitor_hash === $browserHash) {
            $visit->forceFill(['last_seen_at' => $now])->save();
            // The same click again any time later: Analytics reads the old click's day again.
            $this->analytics->handle($visit->network_day, $now);

            return $visit;
        }

        if ($visit->opened_at !== null) {
            return null;
        }

        $visit->forceFill([
            'visitor_hash' => $browserHash,
            'known' => $attributes['known'],
            'known_reason' => $attributes['known_reason'],
            'device' => $attributes['device'],
            'country' => $attributes['country'],
            'region' => $attributes['region'],
            'city' => $attributes['city'],
            'country_group' => $attributes['country_group'],
            'worth_percent' => $attributes['worth_percent'],
            'bot_flags' => $attributes['bot_flags'],
            'last_seen_at' => $now,
            // Priced again for the device that took it, as it came; a spend taken from a day's figures stays.
            ...($visit->spend_basis->isAllocated() ? [] : $this->clickSpend(
                $source,
                $visit->campaign_id === null ? null : TrafficCampaign::query()->find($visit->campaign_id),
                $visit->cost === null ? null : (string) $visit->cost,
                $attributes['device'],
                $visit->arrived_at,
            )),
        ])->save();
        // A click id can come again any time later: Analytics reads the old click's day again.
        $this->analytics->handle($visit->network_day, $now);

        return $visit;
    }

    /** The campaign the click names, added when it is new and brought back when it is archived. */
    private function campaignId(TrafficSource $source, ClickParameters $params, CarbonImmutable $now, bool $write): ?int
    {
        if ($source->type !== TrafficSourceType::Network || $params->campaign === null) {
            return null;
        }

        $campaign = TrafficCampaign::query()->where('source_id', $source->id)->where('external_id', $params->campaign)->first();

        if (! $write) {
            return $campaign?->id;
        }

        if ($campaign !== null) {
            if ($campaign->status === TrafficCampaignStatus::Archived) {
                $this->unarchive($campaign, $now);
            }

            return (int) $campaign->id;
        }

        $addedToday = TrafficCampaign::query()->where('source_id', $source->id)->where('first_click_at', '>=', $now->startOfDay())->count();

        if ($addedToday >= self::NEW_CAMPAIGNS_PER_DAY) {
            return null;
        }

        try {
            return DB::transaction(function () use ($source, $params, $now): int {
                $campaign = new TrafficCampaign;
                $campaign->forceFill([
                    'source_id' => $source->id,
                    'external_id' => $params->campaign,
                    'status' => TrafficCampaignStatus::New,
                    'first_click_at' => $now,
                ])->save();

                (new TrafficCampaignEvent)->forceFill([
                    'campaign_id' => $campaign->id,
                    'kind' => TrafficCampaignEventKind::Created,
                    'summary' => 'First click with campaign ID '.$params->campaign,
                    'actor' => TrafficActor::System,
                    'occurred_at' => $now,
                ])->save();

                return (int) $campaign->id;
            });
        } catch (UniqueConstraintViolationException) {
            return TrafficCampaign::query()->where('source_id', $source->id)->where('external_id', $params->campaign)->value('id');
        }
    }

    private function unarchive(TrafficCampaign $campaign, CarbonImmutable $now): void
    {
        $locked = TrafficCampaign::query()->whereKey($campaign->id)->lockForUpdate()->first();

        if ($locked === null || $locked->status !== TrafficCampaignStatus::Archived) {
            return;
        }

        $locked->forceFill([
            'status' => $locked->status_before_archive ?? ($locked->set_up_at !== null ? TrafficCampaignStatus::Active : TrafficCampaignStatus::New),
            'status_before_archive' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
            'archived_automatically' => false,
        ])->save();

        (new TrafficCampaignEvent)->forceFill([
            'campaign_id' => $locked->id,
            'kind' => TrafficCampaignEventKind::Archive,
            'summary' => 'Back from the archive: a click came in',
            'actor' => TrafficActor::System,
            'occurred_at' => $now,
        ])->save();
    }

    /** Marks a burst from one address, and records nothing past the limits. */
    private function withinLimits(TrafficSource $source, ?string $ipHash, int &$flags): bool
    {
        if ($ipHash !== null) {
            $key = 'traffic-click:'.$ipHash;
            RateLimiter::hit($key, 60);
            $hits = RateLimiter::attempts($key);

            if ($hits > self::BURST_PER_MINUTE) {
                $flags |= BotSignals::BURST;
            }

            if ($hits > self::DROP_PER_MINUTE) {
                $this->dropped('address');

                return false;
            }
        }

        $sourceKey = 'traffic-click-source:'.$source->id;
        RateLimiter::hit($sourceKey, 60);

        if (RateLimiter::attempts($sourceKey) > self::SOURCE_DROP_PER_MINUTE) {
            $this->dropped('source');

            return false;
        }

        return true;
    }

    private function dropped(string $by): void
    {
        $key = 'traffic-click-dropped-log:'.$by;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }

        RateLimiter::hit($key, 60);
        $this->logger->warning('traffic.click.dropped', ['by' => $by]);
    }
}
