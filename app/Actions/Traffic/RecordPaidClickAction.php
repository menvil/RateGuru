<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficKnownReason;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendMode;
use App\Models\Game;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignEvent;
use App\Models\TrafficCampaignPrice;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Observability\DomainLogger;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\ClickParameters;
use App\Support\Traffic\Capture\VisitorSignals;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Spend\ClickSpend;
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
 * address are marked, and past a limit not recorded at all.
 */
final class RecordPaidClickAction
{
    private const int BURST_PER_MINUTE = 60;

    private const int DROP_PER_MINUTE = 600;

    private const int SOURCE_DROP_PER_MINUTE = 3000;

    private const int NEW_CAMPAIGNS_PER_DAY = 50;

    public function __construct(
        private readonly VisitorVisitQuery $visits,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * The visit the click is, or null when it is no click for this browser,
     * or one too many to record.
     *
     * @param  array<array-key, mixed>  $query
     */
    public function handle(
        TrafficSource $source,
        array $query,
        string $browserHash,
        bool $browserIsNew,
        ?int $userId,
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
        $gameId = $gameSlug === null ? null : Game::query()->where('slug', $gameSlug)->value('id');
        $cost = $params->cost === null ? null : TrafficMoney::of($params->cost)->dividedBy($source->cost_unit->divisor(), TrafficMoney::SCALE, RoundingMode::HalfUp);

        if ($cost !== null && $cost->isGreaterThan(10)) {
            $cost = null;
        }

        $attributes = [
            'channel' => $source->type === TrafficSourceType::Network ? TrafficChannel::Network : TrafficChannel::Placement,
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
            'game_id' => $gameId === null ? null : (int) $gameId,
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
        ];

        return DB::transaction(function () use ($source, $params, $attributes, $clickHash, $browserHash, $signals, $now): ?TrafficVisit {
            $attributes['campaign_id'] = $this->campaignId($source, $params, $now, true);
            $attributes = [...$attributes, ...$this->clickSpend($source, $attributes['campaign_id'], $attributes['cost'], $signals->device, $now)];

            try {
                $visit = DB::transaction(function () use ($attributes): TrafficVisit {
                    $visit = new TrafficVisit;
                    $visit->forceFill($attributes)->save();

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

            return $visit;
        });
    }

    /**
     * The same click asked for again: by the same browser, it is the same
     * visit; by another browser, it passes to that browser while no browser
     * has run its page, and is no click for it once one has.
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
        ])->save();

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

    /**
     * What the click is taken to have cost as it comes in (ClickSpend), by
     * how its campaign — else its source — counts spend; a campaign priced
     * by the day uses the price in force now.
     *
     * @return array{spend: string, spend_basis: TrafficSpendBasis, spend_estimated: bool}
     */
    private function clickSpend(TrafficSource $source, ?int $campaignId, ?string $cost, TrafficDevice $device, CarbonImmutable $now): array
    {
        $campaign = $campaignId === null ? null : TrafficCampaign::query()->find($campaignId);
        $mode = $campaign->spend_mode ?? $source->spend_mode;
        $price = $mode === TrafficSpendMode::Price && $campaign !== null
            ? TrafficCampaignPrice::query()->where('campaign_id', $campaign->id)->where('device', $device)->where('effective_from', '<=', $now)
                ->orderByDesc('effective_from')->orderByDesc('id')->first()
            : null;
        $spend = ClickSpend::of(
            $mode,
            $cost,
            $device === TrafficDevice::Mobile ? $source->rate_mobile : $source->rate_desktop,
            $source->rate_unit,
            $price === null ? null : ['price' => (string) $price->price, 'model' => $price->bid_model],
            $source->spend_estimate,
        );

        return ['spend' => $spend['spend'], 'spend_basis' => $spend['basis'], 'spend_estimated' => $spend['estimated']];
    }
}
