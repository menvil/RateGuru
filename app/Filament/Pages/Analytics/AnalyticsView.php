<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\AnalyticsDimension;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\TrafficCampaign;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use Carbon\CarbonImmutable;

/**
 * What the shell of Growth → Analytics shows around its tabs
 * (GrowthAnalyticsPage): the sources and campaigns its filters offer, the
 * chip of the source chosen, and what it says about what is recorded — the
 * switches that keep visits from being recorded, and the day the first visit
 * was. Until the page is in a browser it offers only the source and campaign
 * already chosen and reads no visits; then the sources the most people came
 * from in the period, and the campaigns of the chosen source. Reads only.
 */
final class AnalyticsView
{
    /** What the page says while visits are recorded but not those of the site's own pages. */
    public const string SITE_OFF = 'Only game visits and paid clicks are recorded. Switch on Record site visits to see the whole site.';

    /** How many sources the Source filter offers, besides the one chosen. */
    public const int SOURCE_OPTIONS = 30;

    /** How many paid campaigns the Campaign filter offers, the latest first, and as many campaigns named by their tags. */
    public const int CAMPAIGN_OPTIONS = 100;

    /** When the first visit was recorded, once read: the hints and the Tracking card ask the same request. */
    private ?CarbonImmutable $recordedSince = null;

    private bool $recordedSinceRead = false;

    public function __construct(private readonly AnalyticsVisitsQuery $visits) {}

    /**
     * @return array{sourceOptions: list<array{value: string, label: string, trigger?: string}>, campaignOptions: list<array{value: string, label: string, trigger?: string}>, chip: string|null, notices: list<array{tone: string, text: string, action: array{label: string, url: string}|null}>}
     */
    public function shell(AnalyticsFilter $filter, bool $ready, ?CarbonImmutable $recordedSince): array
    {
        $sourceKeys = $ready
            ? array_values(array_filter(array_column($this->visits->top(new AnalyticsFilter($filter->period), AnalyticsDimension::Source, self::SOURCE_OPTIONS), 'source_key'), 'is_string'))
            : [];

        if ($filter->sourceKey !== null && ! in_array($filter->sourceKey, $sourceKeys, true)) {
            $sourceKeys[] = $filter->sourceKey;
        }

        $sourceNames = $this->sourceNames($sourceKeys);

        return [
            'sourceOptions' => [
                ['value' => '', 'label' => 'All sources', 'trigger' => 'All'],
                ...array_map(fn (string $key): array => ['value' => $key, 'label' => self::sourceLabel($key, $sourceNames)], $sourceKeys),
            ],
            'campaignOptions' => [
                ['value' => '', 'label' => 'All campaigns', 'trigger' => 'All'],
                ...$this->campaignOptions($filter, $ready),
            ],
            'chip' => $filter->sourceKey === null ? null : self::sourceLabel($filter->sourceKey, $sourceNames),
            'notices' => $this->notices($filter, $ready, $recordedSince),
        ];
    }

    /** When the first visit was recorded; none before the first. */
    public function recordedSince(): ?CarbonImmutable
    {
        if (! $this->recordedSinceRead) {
            $this->recordedSince = $this->visits->recordedSince();
            $this->recordedSinceRead = true;
        }

        return $this->recordedSince;
    }

    /**
     * Whether the filter's campaign is one of its source's: a paid campaign
     * of that paid source, or a campaign named by its tag that came with the
     * source in the period. Always with no source or no campaign chosen.
     */
    public function campaignBelongs(AnalyticsFilter $filter): bool
    {
        if ($filter->sourceKey === null || $filter->campaignKey === null) {
            return true;
        }

        $campaignId = CampaignKey::paidCampaignId($filter->campaignKey);

        if ($campaignId !== null) {
            $sourceId = AnalyticsSource::paidSourceId($filter->sourceKey);

            return $sourceId !== null && TrafficCampaign::query()->whereKey($campaignId)->where('source_id', $sourceId)->exists();
        }

        return $this->visits->has(new AnalyticsFilter($filter->period, null, $filter->sourceKey, $filter->campaignKey));
    }

    /**
     * The names of the paid sources among the keys given, by id: what a paid
     * source's key reads as.
     *
     * @param  list<string|null>  $keys
     * @return array<int, string>
     */
    public function sourceNames(array $keys): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (?string $key): ?int => $key === null ? null : AnalyticsSource::paidSourceId($key), $keys))));

        if ($ids === []) {
            return [];
        }

        return TrafficSource::query()->whereKey($ids)->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all();
    }

    /**
     * The names of the paid campaigns among the keys given, by id, a campaign
     * with no name by its id on the network — with its source's name after
     * it when asked, since networks' campaigns often share a name.
     *
     * @param  list<string|null>  $keys
     * @return array<int, string>
     */
    public function campaignNames(array $keys, bool $withSource = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (?string $key): ?int => $key === null ? null : CampaignKey::paidCampaignId($key), $keys))));

        if ($ids === []) {
            return [];
        }

        return TrafficCampaign::query()->whereKey($ids)->when($withSource, fn ($query) => $query->with('source:id,name'))->get(['id', 'name', 'external_id', 'source_id'])
            ->mapWithKeys(fn (TrafficCampaign $campaign): array => [(int) $campaign->id => self::campaignName($campaign, $withSource)])
            ->all();
    }

    /** A paid campaign's name, or its id on the network, and — asked — its source's name. */
    private static function campaignName(TrafficCampaign $campaign, bool $withSource): string
    {
        $name = $campaign->name ?? 'Campaign '.$campaign->external_id;

        return $withSource && $campaign->source !== null ? $name.' · '.$campaign->source->name : $name;
    }

    /**
     * A source as the filters and the chip name it: “Reddit · organic_social”.
     *
     * @param  array<int, string>  $names  the paid sources' names, by id
     */
    public static function sourceLabel(string $key, array $names): string
    {
        return AnalyticsSource::label($key, $names).' · '.AnalyticsSource::medium($key);
    }

    /**
     * The campaigns the Campaign filter offers, the one chosen always among
     * them: once the page is in a browser, the latest paid campaigns — of the
     * chosen paid source only, none for another source — then the campaigns
     * named by their tags that came in the period, from the chosen source
     * only, the most people first.
     *
     * @return list<array{value: string, label: string}>
     */
    private function campaignOptions(AnalyticsFilter $filter, bool $ready): array
    {
        $chosen = $filter->campaignKey;
        $sourceId = $filter->sourceKey === null ? null : AnalyticsSource::paidSourceId($filter->sourceKey);
        $options = [];

        if ($ready && ($filter->sourceKey === null || $sourceId !== null)) {
            // With every source listed, a campaign carries its source's name: networks' campaigns often share one.
            foreach (TrafficCampaign::query()->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId), fn ($query) => $query->with('source:id,name'))
                ->orderByDesc('first_click_at')->orderByDesc('id')->limit(self::CAMPAIGN_OPTIONS)->get(['id', 'name', 'external_id', 'source_id']) as $campaign) {
                $options[(string) CampaignKey::paid((int) $campaign->id)] = self::campaignName($campaign, $sourceId === null);
            }
        }

        if ($ready) {
            $tagged = array_column($this->visits->top(new AnalyticsFilter($filter->period, null, $filter->sourceKey), AnalyticsDimension::Campaign, self::CAMPAIGN_OPTIONS), 'campaign_key');

            foreach ($tagged as $key) {
                if (is_string($key) && CampaignKey::paidCampaignId($key) === null && CampaignKey::read($key) !== null) {
                    $options[$key] = CampaignKey::label($key);
                }
            }
        }

        if ($chosen !== null && ! array_key_exists($chosen, $options)) {
            $options[$chosen] = CampaignKey::label($chosen, $this->campaignNames([$chosen], withSource: $filter->sourceKey === null));
        }

        return array_map(fn (string $key, string $label): array => ['value' => $key, 'label' => $label], array_keys($options), array_values($options));
    }

    /**
     * What the page says about what is recorded: nothing new while Record
     * visits is off, only game visits and paid clicks while Record site
     * visits is, and — once the page is in a browser — the day visits are
     * recorded since, when the period starts before it.
     *
     * @return list<array{tone: string, text: string, action: array{label: string, url: string}|null}>
     */
    private function notices(AnalyticsFilter $filter, bool $ready, ?CarbonImmutable $recordedSince): array
    {
        $flags = TrafficSetting::captureFlags();
        $tracking = ['label' => 'Open Tracking', 'url' => TrafficSourcesPage::getUrl(['tab' => 'sources'])];
        $notices = [];

        if (! $flags['capture']) {
            $notices[] = ['tone' => 'warning', 'text' => 'Record visits is off, so Analytics has nothing new to count. Switch it on under Traffic sources → Sources → Tracking.', 'action' => $tracking];
        } elseif (! $flags['site']) {
            $notices[] = ['tone' => 'info', 'text' => self::SITE_OFF, 'action' => $tracking];
        }

        // Only for a period that reaches the first recorded day: one that ends before it says so as its own state.
        if ($ready && $recordedSince !== null && $recordedSince->toDateString() > $filter->fromDay() && $recordedSince->toDateString() <= $filter->toDay()) {
            $notices[] = ['tone' => 'info', 'text' => 'Visits are recorded since '.$recordedSince->format('d M Y').'.', 'action' => null];
        }

        return $notices;
    }
}
