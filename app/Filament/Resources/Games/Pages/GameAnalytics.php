<?php

namespace App\Filament\Resources\Games\Pages;

use App\Enums\TrafficDevice;
use App\Filament\Concerns\ChoosesReportPeriod;
use App\Filament\Resources\Games\Analytics\GameAnalyticsView;
use App\Filament\Resources\Games\Analytics\GameAnalyticsWindow;
use App\Filament\Resources\Games\Analytics\ReadsGameAnalytics;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

/**
 * A game's Analytics tab: how far its visitors got — anonymous visitors and
 * logged-in players apart — over a period, for every version or one, from
 * every device and country or one, against the window of the same length
 * before it: the figures and what the visits were worth, the versions on
 * paid traffic compared, the visitors by where they came from, the funnel,
 * who signed up and whether they confirmed, how many attempts reached each
 * round, which results players earned, the versions, flows, content sets,
 * devices, countries or country groups side by side, and the traffic periods
 * the figures were made in.
 *
 * What it draws is the game's analytics as Growth → Analytics → Games draws
 * it too (GameAnalyticsView), read for the period of the page's own picker —
 * its presets, all time, or a range of days — and the page's own device, with
 * the game's filters (ReadsGameAnalytics).
 */
final class GameAnalytics extends Page
{
    use ChoosesReportPeriod;
    use InteractsWithGameEditor;
    use ReadsGameAnalytics;

    /** The windows a game's figures are read over — every report page's presets, by whole days up to now; all time has none. */
    public const PERIODS = ReportPeriod::PRESETS;

    /** The name the administrator's last choice of period is kept under (ReportPeriodMemory): one for every game's Analytics. */
    public const string PERIOD_MEMORY = 'game-analytics';

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-analytics';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** Every device, or one: a TrafficDevice. */
    #[Url(except: '')]
    public mixed $device = '';

    public function mount(int|string $record): void
    {
        $this->mountGame($record);
        $this->openPeriod();
        $this->normalizeFilters();
    }

    public function updated(): void
    {
        $this->normalizeFilters();
    }

    /**
     * Puts every filter back as it is read — the period, the device and the
     * game's own filters — so the address, the screen and the figures agree
     * on whatever the address or an update sent.
     */
    private function normalizeFilters(): void
    {
        $this->settlePeriod();
        $this->device = is_string($this->device) && TrafficDevice::tryFrom($this->device) !== null ? $this->device : '';
        $this->settleGameAnalytics($this->gameId);
    }

    /** The figures go back as far as both the game's events and the recorded visits are kept. */
    protected function keptDays(): int
    {
        return self::retentionDays();
    }

    /** How many days back, today included, a game's figures can be read: what a range may start from. */
    public static function retentionDays(): int
    {
        return min((int) config('games.retention_days.events'), (int) config('traffic.retention_days.visits'));
    }

    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $period = $this->reportPeriod();
        // Up to and including the current second: the database keeps whole seconds.
        $now = CarbonImmutable::now()->startOfSecond()->addSecond();
        $window = self::window($period, $now, $this->keptDays());

        return [
            'header' => $this->editorHeader($game, 'analytics'),
            'periodPicker' => $this->periodPicker(
                $window->allTime ? null : $window->dates(),
                // The days the trigger names, drawn as the calendar's range; all time names none, so its calendar has no range.
                $window->allTime
                    ? ['', '']
                    : [$window->from->toDateString(), $window->until->subSecond()->toDateString()],
            ),
            ...app(GameAnalyticsView::class)->read(
                $game,
                $window,
                is_string($this->device) ? TrafficDevice::tryFrom($this->device) : null,
                $this->audience,
                $this->version,
                $this->country,
                $this->breakdown,
                $this->paidSource,
                $this->sourcesDevice,
            ),
        ];
    }

    /**
     * The window a period reads, from its first moment up to the one after
     * its last: a preset's days up to now — whole UTC days, today's included,
     * the days the trigger names, as a custom range and Traffic sources count
     * them — compared as the preset is (GameAnalyticsWindow::comparisonOf());
     * a range's whole days, up to now where it ends today; or all time.
     */
    private static function window(ReportPeriod $period, CarbonImmutable $now, int $keptDays): GameAnalyticsWindow
    {
        $links = self::reportPeriodParameters($period);

        if ($period->preset === ReportPeriod::ALL) {
            return GameAnalyticsWindow::allTime($now, $links);
        }

        [$start, $end] = [$period->start(), $period->end()];

        if ($start !== null && $end !== null) {
            return GameAnalyticsWindow::days($start, $end, $now, $keptDays, null, $links);
        }

        [$first, $last] = ReportPeriod::presetDays($period->preset, $now->subSecond(), $keptDays);

        return GameAnalyticsWindow::days($first, $last->addDay(), $now, $keptDays, strtolower(self::PERIODS[$period->preset]), $links, GameAnalyticsWindow::comparisonOf($period->preset));
    }

    /**
     * The period of the links to Traffic sources, whose picker offers the
     * same presets: the same preset, or the same days for a range — always
     * named, since an address that names none opens on the period last
     * chosen there.
     *
     * @return array<string, string|null>
     */
    private static function reportPeriodParameters(ReportPeriod $period): array
    {
        if ($period->isCustom()) {
            return ['from' => $period->fromDay, 'to' => $period->toDay];
        }

        return ['period' => $period->preset];
    }
}
