<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\TrackingLinkDestination;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Resources\Games\GameResource;
use App\Models\TrackingLink;
use App\Models\User;
use App\Queries\Analytics\TrackingLinksQuery;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\TrackingLinkUrl;
use App\Support\Analytics\VisitorFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Analytics → Tracking links, as the reference draws it: the administrator's
 * tagged addresses for posts, ads and emails, the newest first, searched by
 * name, source or tag and narrowed by the page's source and campaign — each
 * with where it leads, its source and medium, its campaign and creative, and
 * the people of the period and device who came through it: visitors, and
 * accounts and activated users with their rates of new visitors ("—" below
 * the denominator a row needs). A link no visit ever came through says so
 * instead of zeros. Each row copies the address, opens the link's campaign
 * and creative on the Campaigns tab, edits it — renames it once visits came
 * through — or duplicates it; a click on it opens its details: the address
 * and its preview, the link's figures, its tags, and the same actions with
 * the way to its game. The drawer that makes and edits a link is its own
 * component (TrackingLinkEditor). Reads only.
 */
final class TrackingLinksTab implements AnalyticsTab
{
    public function __construct(private readonly TrackingLinksQuery $links) {}

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        ['links' => $links, 'total' => $total] = $this->links->list((string) $page->q, $filter);
        $shown = $page->show === '' ? null : ($links->firstWhere('id', (int) $page->show) ?? $this->one((int) $page->show));
        $figures = $this->links->figures($shown === null || $links->contains('id', $shown->id) ? $links : $links->concat([$shown]), $filter);
        $pages = $this->links->pages();
        $minimum = (int) config('analytics.row_min_denominator');
        $now = CarbonImmutable::now();
        $period = $filter->period;
        $when = $period->isCustom() ? $period->range() : $period->label();
        $narrowed = trim((string) $page->q) !== '' || $filter->narrowsSource();

        return [
            'description' => 'Tagged URLs for posts, ads and emails. A link only labels the traffic that arrives through it. It doesn’t start, pause or change an ad campaign.',
            'count' => number_format($total).' '.str('link')->plural($total),
            'more' => $total > $links->count() ? 'The '.$links->count().' newest of '.number_format($total).' links are listed. Search to find the others.' : null,
            'rows' => $links->map(fn (TrackingLink $link): array => $this->row($link, $figures[(int) $link->id] ?? null, $pages, $filter, $minimum, $now, (int) $page->show === (int) $link->id))->values()->all(),
            'none' => $total > 0 ? null : ($narrowed ? 'No tracking links match these filters.' : 'No tracking links yet. Create one for a post, an ad or an email, and the visitors who come through it are counted here.'),
            'foot' => 'Visitors, registrations and activated users: '.mb_strtolower($when).($filter->device === null ? '' : ' on '.mb_strtolower($filter->device->label()))
                .', unique visitors whose visit came through each URL, the visits after it in the same session included. Registration and activation use new visitors; a rate shows “—” until the number it divides by reaches '.$minimum.'. A link with no visits shows none, not zeros.',
            'detail' => $shown === null ? null : $this->detail($shown, $figures[(int) $shown->id] ?? new VisitorFigures, $pages, $filter, $minimum, $when),
            'editing' => $this->editing((string) $page->edit),
        ];
    }

    /** The address a link gives out: the site's origin, its path and its tags. */
    public static function url(TrackingLink $link): string
    {
        return TrackingLinkUrl::compose(self::origin(), $link->path, $link->tags()->columns());
    }

    /** The site's scheme and host, which every link's address starts with. */
    public static function origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Where View analytics leads: the Campaigns tab with the link's
     * campaign chosen, its creative — its content, else its term — opened
     * and marked, the link named for the banner, on the same days and device.
     */
    public static function analyticsUrl(TrackingLink $link, AnalyticsFilter $filter): string
    {
        $period = $filter->period;

        return GrowthAnalyticsPage::getUrl(array_filter([
            'tab' => 'campaigns',
            'campaign' => CampaignKey::tag($link->utm_campaign),
            'creative' => $link->tags()->creative() ?? CampaignKey::NO_CREATIVE,
            'link' => $link->id,
            'period' => $period->isCustom() ? null : $period->preset,
            'from' => $period->isCustom() ? $period->fromDay : null,
            'to' => $period->isCustom() ? $period->toDay : null,
            'device' => $filter->device?->value,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * A row of the list.
     *
     * @param  array<string, string>  $pages
     * @return array<string, mixed>
     */
    private function row(TrackingLink $link, ?VisitorFigures $figures, array $pages, AnalyticsFilter $filter, int $minimum, CarbonImmutable $now, bool $selected): array
    {
        $visited = $link->getAttribute('visit_id') !== null;
        $figures ??= new VisitorFigures;
        $ofNew = fn (?float $rate): ?string => $rate === null ? null : self::percent($rate).' of new visitors';

        return [
            'id' => (int) $link->id,
            'reference' => $link->reference(),
            'name' => $link->name,
            'new' => $link->created_at->greaterThan($now->subDay()),
            'destination' => self::destination($link, $pages),
            'source' => TrackingLinkUrl::sourceLabel($link->utm_source),
            'medium' => $link->utm_medium,
            'campaign' => $link->utm_campaign,
            'creative' => self::creative($link),
            'visited' => $visited,
            'visitors' => $visited ? number_format($figures->visitors) : 'No visits yet',
            'accounts' => ['value' => $visited ? number_format($figures->accounts) : '—', 'rate' => $visited ? $ofNew($figures->accountRate($minimum)) : null],
            'activated' => ['value' => $visited ? number_format($figures->activated) : '—', 'rate' => $visited ? $ofNew($figures->activationRate($minimum)) : null],
            'created' => $link->created_at->format('d M Y'),
            'by' => self::creator($link),
            'url' => self::url($link),
            'analytics' => self::analyticsUrl($link, $filter),
            'editLabel' => $visited ? 'Rename' : 'Edit',
            'selected' => $selected,
        ];
    }

    /**
     * A link's details: the address and where its preview opens; whether any
     * visit ever came through it, and if so its figures of the period and
     * device; its tags; and the actions.
     *
     * @param  array<string, string>  $pages
     * @return array<string, mixed>
     */
    private function detail(TrackingLink $link, VisitorFigures $figures, array $pages, AnalyticsFilter $filter, int $minimum, string $when): array
    {
        $visited = $link->getAttribute('visit_id') !== null;
        $rate = fn (?float $value, string $of): string => $value === null ? '—' : self::percent($value).' of '.$of;
        $game = $link->destination === TrackingLinkDestination::Game && $link->game_id !== null && Gate::allows('manage-games') ? $link->game_id : null;

        return [
            'id' => (int) $link->id,
            'name' => $link->name,
            'sub' => $link->reference().' · created '.$link->created_at->format('d M Y').' by '.self::creator($link),
            'url' => self::url($link),
            'preview' => $game !== null ? GameResource::getUrl('preview', ['record' => $game]) : self::origin().$link->path,
            'empty' => ! $visited,
            'metrics' => [
                ['label' => 'Visitors', 'value' => number_format($figures->visitors), 'note' => 'Unique visitors'],
                ['label' => 'Game starts', 'value' => number_format($figures->starters), 'note' => $rate($figures->startRate($minimum), 'visitors')],
                ['label' => 'Completions', 'value' => number_format($figures->completers), 'note' => $rate($figures->completion($minimum), 'starters')],
                ['label' => 'Registrations', 'value' => number_format($figures->accounts), 'note' => $rate($figures->accountRate($minimum), 'new visitors')],
                ['label' => 'Activated', 'value' => number_format($figures->activated), 'note' => $rate($figures->activationRate($minimum), 'new visitors')],
            ],
            'period' => $when.($filter->device === null ? '' : ' · '.$filter->device->label()).' · counted by RateGuru',
            'meta' => [
                ['icon' => 'map-pin', 'label' => 'Destination', 'value' => self::destination($link, $pages)],
                ['icon' => 'radio', 'label' => 'Source', 'value' => TrackingLinkUrl::sourceLabel($link->utm_source)],
                ['icon' => 'layers', 'label' => 'Medium', 'value' => $link->utm_medium],
                ['icon' => 'megaphone', 'label' => 'Campaign', 'value' => $link->utm_campaign],
                ['icon' => 'image', 'label' => 'Creative', 'value' => $link->utm_content === '' ? '—' : $link->utm_content],
                ['icon' => 'search', 'label' => 'Term', 'value' => $link->utm_term === '' ? '—' : $link->utm_term],
                ['icon' => 'calendar', 'label' => 'Created', 'value' => $link->created_at->format('d M Y').' · '.self::creator($link)],
            ],
            'editLabel' => $visited ? 'Rename' : 'Edit',
            'game' => $game === null ? null : GameResource::getUrl('analytics', ['record' => $game]),
            'analytics' => self::analyticsUrl($link, $filter),
        ];
    }

    /**
     * The drawer the address opens: a new link's, a link's own, or a new one
     * made from a link's fields; none for a link that isn't there.
     *
     * @return array{linkId: int, copyOf: int, key: string}|null
     */
    private function editing(string $edit): ?array
    {
        if ($edit === '') {
            return null;
        }

        if ($edit === 'new') {
            return ['linkId' => 0, 'copyOf' => 0, 'key' => 'new'];
        }

        $copy = str_starts_with($edit, 'copy-');
        $id = (int) ($copy ? substr($edit, 5) : $edit);

        if (! TrackingLink::query()->whereKey($id)->exists()) {
            return null;
        }

        return ['linkId' => $copy ? 0 : $id, 'copyOf' => $copy ? $id : 0, 'key' => $edit];
    }

    /** A link the list doesn't hold — one saved just now that the search or filters leave out — with whether a visit came through it, as the list reads it. */
    private function one(int $id): ?TrackingLink
    {
        $link = TrackingLink::query()->with(['game:id,title,slug,status', 'createdBy:id,name,username,status'])->find($id);

        if ($link instanceof TrackingLink) {
            $link->setAttribute('visit_id', $this->links->hasVisits($link) ? 1 : null);
        }

        return $link;
    }

    /**
     * Where a link leads, as its row says it: a game by its title, a page of
     * the site by its name, then the path.
     *
     * @param  array<string, string>  $pages
     */
    private static function destination(TrackingLink $link, array $pages): string
    {
        $path = rawurldecode($link->path);

        return match ($link->destination) {
            TrackingLinkDestination::Game => ($link->game->title ?? 'Game #'.$link->game_id).' · '.$path,
            TrackingLinkDestination::Page => isset($pages[$link->path]) ? $pages[$link->path].' · '.$path : $path,
            TrackingLinkDestination::Custom => $path,
        };
    }

    /** A link's creative as its row says it: its content and term, those there are. */
    private static function creative(TrackingLink $link): string
    {
        $parts = array_values(array_filter([$link->utm_content, $link->utm_term], fn (string $tag): bool => $tag !== ''));

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /** Who made a link, by name; a deleted account as such. */
    private static function creator(TrackingLink $link): string
    {
        $user = $link->createdBy;

        if (! $user instanceof User || $user->isTombstoned()) {
            return 'Deleted user';
        }

        return (string) ($user->name ?: $user->username);
    }

    private static function percent(float $rate): string
    {
        return number_format($rate * 100, 1).'%';
    }
}
