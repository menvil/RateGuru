<?php

namespace App\Queries\Analytics;

use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficCampaign;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What happened in an Analytics period that a chart marks on its day: a
 * game's version published, a paid campaign's first click, and a traffic
 * period that started sharing its visitors between two versions or more —
 * still listed in Growth → Experiments — with the audience it shares and, for
 * a split for a source, that source and campaign. Only
 * what the filter's source and campaign can have had a part in: a paid
 * campaign's start only with no source chosen or its own paid source, and no
 * campaign chosen or itself. The newest first, at most the number asked for.
 * Read-only.
 */
final class AnalyticsMarkersQuery
{
    /**
     * @return list<array{at: CarbonImmutable, text: string}> the newest first
     */
    public function in(AnalyticsFilter $filter, int $most): array
    {
        $most = max(0, $most);

        if ($most === 0) {
            return [];
        }

        $from = $filter->period->start();
        $before = $filter->period->end();
        $markers = [];

        foreach (GameVersion::query()->with('game:id,title')->where('published_at', '>=', $from)->where('published_at', '<', $before)
            ->orderByDesc('published_at')->orderByDesc('id')->limit($most)->get() as $version) {
            $markers[] = ['at' => $version->published_at, 'text' => 'V'.$version->number.' of '.($version->game->title ?? 'a game').' published'];
        }

        $campaigns = $this->campaigns($filter);

        if ($campaigns !== null) {
            foreach ($campaigns->where('first_click_at', '>=', $from)->where('first_click_at', '<', $before)
                ->orderByDesc('first_click_at')->orderByDesc('id')->limit($most)->get(['id', 'name', 'external_id', 'first_click_at']) as $campaign) {
                $markers[] = ['at' => $campaign->first_click_at, 'text' => ($campaign->name ?? 'Campaign '.$campaign->external_id).' started'];
            }
        }

        $periods = GameTrafficPeriod::query()->with(['game:id,title', 'versions'])->whereNull('hidden_at')->has('versions', '>=', 2)
            ->where('started_at', '>=', $from)->where('started_at', '<', $before)
            ->orderByDesc('started_at')->orderByDesc('id')->limit($most)->get();
        // A split for a source names its source and campaign; the general split's periods read as they always have.
        $periods->whereNotNull('source_id')->load(['source:id,name', 'campaign:id,source_id,external_id,name']);

        foreach ($periods as $period) {
            $versions = $period->versions->map(fn (GameVersion $version): string => 'V'.$version->number)->implode(' · ');
            $scope = $period->scopeLabel();
            $markers[] = ['at' => $period->started_at, 'text' => ($period->game->title ?? 'A game').': '.$versions.' comparison started for '.mb_strtolower($period->audience->label()).($scope !== null ? ' · '.$scope : '')];
        }

        usort($markers, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($markers, 0, $most);
    }

    /**
     * The paid campaigns the filter's source and campaign leave in: every one
     * with neither chosen, those of a paid source, the paid campaign chosen;
     * none for any other source or a campaign named by its tag.
     *
     * @return Builder<TrafficCampaign>|null
     */
    private function campaigns(AnalyticsFilter $filter): ?Builder
    {
        $sourceId = $filter->sourceKey === null ? null : AnalyticsSource::paidSourceId($filter->sourceKey);
        $campaignId = $filter->campaignKey === null ? null : CampaignKey::paidCampaignId($filter->campaignKey);

        if (($filter->sourceKey !== null && $sourceId === null) || ($filter->campaignKey !== null && $campaignId === null)) {
            return null;
        }

        return TrafficCampaign::query()
            ->when($sourceId !== null, fn (Builder $query) => $query->where('source_id', $sourceId))
            ->when($campaignId !== null, fn (Builder $query) => $query->whereKey($campaignId));
    }
}
