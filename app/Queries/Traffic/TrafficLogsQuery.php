<?php

namespace App\Queries\Traffic;

use App\Contracts\Persistence\StablePaginationBoundary;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficVisit;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * The four logs of Growth → Traffic sources, newest first, fifty to a page:
 * clicks and visits — those the tracker counts, paid clicks and game visits
 * (TrafficVisit::scopeTracked()) — the milestones paid clicks reached, the
 * postbacks and what the networks answered, and blocking. A page knows only
 * whether there is a next one, so no log ever counts its rows.
 */
final class TrafficLogsQuery implements StablePaginationBoundary
{
    public const int PER_PAGE = 50;

    /** @return Paginator<int, TrafficVisit> */
    public function clicks(?int $sourceId, ?int $campaignId, int $page): Paginator
    {
        return TrafficVisit::query()
            ->tracked()
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->when($campaignId !== null, fn ($query) => $query->where('campaign_id', $campaignId))
            ->with('detail')
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /** @return Paginator<int, TrafficConversion> */
    public function milestones(?int $sourceId, int $page): Paginator
    {
        return TrafficConversion::query()
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /** @return Paginator<int, TrafficPostback> */
    public function postbacks(?int $sourceId, ?string $status, int $page): Paginator
    {
        return TrafficPostback::query()
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /** @return Paginator<int, TrafficBlockEvent> */
    public function blocks(?int $sourceId, int $page): Paginator
    {
        return TrafficBlockEvent::query()
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }
}
