<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\GameStatus;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\Analytics\GameAnalyticsView;
use App\Filament\Resources\Games\Analytics\GameAnalyticsWindow;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Models\Game;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Analytics → Games, as the reference draws it: a game's own analytics —
 * the very cards and figures of its Analytics tab (GameAnalyticsView) — for
 * the game chosen among those not archived, the address's or else the first,
 * read for the page's period and device with the game's own filters
 * (ReadsGameAnalytics) beside them. The source and campaign filters don't
 * apply: a game's figures come from what playing recorded, and the page says
 * so while one is chosen. No game yet says so. Reads only.
 */
final class GamesTab implements AnalyticsTab
{
    public function __construct(private readonly GameAnalyticsView $games) {}

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $games = self::games();
        $game = $games->firstWhere('id', (int) $page->game) ?? $games->first();

        if ($game === null) {
            return ['empty' => true, 'gamesUrl' => GameResource::getUrl()];
        }

        $period = $filter->period;
        // Up to and including the current second: the database keeps whole seconds.
        $now = CarbonImmutable::now()->startOfSecond()->addSecond();
        $window = GameAnalyticsWindow::days($period->start(), $period->end(), $now, GameAnalytics::retentionDays(), $period->isCustom() ? null : strtolower($period->label()), self::links($period));

        return [
            'empty' => false,
            'gameChoices' => $games->map(fn (Game $each): array => ['id' => (int) $each->id, 'title' => $each->title, 'chosen' => (int) $each->id === (int) $game->id])->values()->all(),
            ...$this->games->read($game, $window, $filter->device, $page->audience, $page->version, $page->country, $page->breakdown, $page->paidSource, $page->sourcesDevice),
        ];
    }

    /**
     * The games the tab offers: every one not archived, the oldest first.
     *
     * @return Collection<int, Game>
     */
    public static function games(): Collection
    {
        return Game::query()->where('status', '!=', GameStatus::Archived->value)->orderBy('id')->get();
    }

    /**
     * The game the tab shows for the one the address names: that one while
     * it is offered, else the first; none while there is no game.
     */
    public static function chosen(mixed $game): ?int
    {
        $ids = Game::query()->where('status', '!=', GameStatus::Archived->value)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $asked = (is_int($game) || is_string($game)) && preg_match('/^[1-9]\d{0,17}$/', (string) $game) === 1 ? (int) $game : 0;

        return $ids->contains($asked) ? $asked : $ids->first();
    }

    /**
     * The address of a game's own analytics on this tab, of the same days and
     * device as the filter: the source and campaign don't apply here — what
     * a game's row elsewhere on the page leads to.
     */
    public static function url(int $gameId, AnalyticsFilter $filter): string
    {
        $period = $filter->period;

        return GrowthAnalyticsPage::getUrl(array_filter([
            'tab' => 'games',
            'game' => $gameId,
            'period' => $period->isCustom() ? null : $period->preset,
            'from' => $period->isCustom() ? $period->fromDay : null,
            'to' => $period->isCustom() ? $period->toDay : null,
            'device' => $filter->device?->value,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * The period of the links to Traffic sources: the same preset where it
     * has one, else the same days — always named, since an address that
     * names none opens on the period last chosen there.
     *
     * @return array<string, string|null>
     */
    private static function links(AnalyticsPeriod $period): array
    {
        return array_key_exists($period->preset, TrafficSourcesPage::PERIODS)
            ? ['period' => $period->preset]
            : ['from' => $period->fromDay, 'to' => $period->toDay];
    }
}
