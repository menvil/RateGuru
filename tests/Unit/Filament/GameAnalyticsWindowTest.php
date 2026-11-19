<?php

use App\Filament\Resources\Games\Analytics\GameAnalyticsWindow;
use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/*
 * The window a game's analytics reads and the one it is compared with, for
 * each of the report pages' presets: as long a time right before it; this
 * month as far into last month as it has gone, last month the whole month
 * before; today alone the day before up to the same time; all time — and a
 * window that asks for nothing — with nothing; none once those days are no
 * longer kept. The filter bar's text says which.
 */

/** A preset's window as a game's Analytics reads it, at a moment given. */
function gameWindow(string $preset, string $now = '2026-11-19 10:00:00', int $keptDays = 400): GameAnalyticsWindow
{
    $now = CarbonImmutable::parse($now, 'UTC');
    [$first, $last] = ReportPeriod::presetDays($preset, $now->subSecond(), $keptDays);

    return GameAnalyticsWindow::days($first, $last->addDay(), $now, $keptDays, strtolower(ReportPeriod::PRESETS[$preset]), [], GameAnalyticsWindow::comparisonOf($preset));
}

/** @return list<string|null> the window's first moment, its end, the window before's first moment and its end */
function gameWindowMoments(GameAnalyticsWindow $window): array
{
    return array_map(fn (?CarbonImmutable $moment): ?string => $moment?->toDateTimeString(), [$window->from, $window->until, $window->before, $window->beforeUntil]);
}

it('compares each preset with the time its comparison says', function (string $preset, array $moments, string $text) {
    $window = gameWindow($preset);

    expect(gameWindowMoments($window))->toBe($moments)
        ->and($window->text())->toBe($text)
        ->and($window->name)->toBe(strtolower(ReportPeriod::PRESETS[$preset]));
})->with([
    'today: the day before up to the same time' => ['today', ['2026-11-19 00:00:00', '2026-11-19 10:00:00', '2026-11-18 00:00:00', '2026-11-18 10:00:00'], '19 Nov 2026 · compared with 18 Nov 2026 up to the same time'],
    'yesterday: the day before' => ['yesterday', ['2026-11-18 00:00:00', '2026-11-19 00:00:00', '2026-11-17 00:00:00', '2026-11-18 00:00:00'], '18 Nov 2026 · compared with 17 Nov 2026'],
    'last 7 days: as long a time before' => ['7d', ['2026-11-13 00:00:00', '2026-11-19 10:00:00', '2026-11-06 14:00:00', '2026-11-13 00:00:00'], '13 Nov — 19 Nov 2026 · compared with 06 Nov — 12 Nov 2026'],
    'this month: as far into last month' => ['this-month', ['2026-11-01 00:00:00', '2026-11-19 10:00:00', '2026-10-01 00:00:00', '2026-10-19 10:00:00'], '01 Nov — 19 Nov 2026 · compared with 01 Oct — 19 Oct 2026 up to the same time'],
    'last month: the whole month before' => ['last-month', ['2026-10-01 00:00:00', '2026-11-01 00:00:00', '2026-09-01 00:00:00', '2026-10-01 00:00:00'], '01 Oct — 31 Oct 2026 · compared with 01 Sep — 30 Sep 2026'],
]);

it('compares this month with the whole of a shorter last month once it has run past its days, and last month with a longer whole month before', function () {
    $march = gameWindow('this-month', '2027-03-31 10:00:00');
    $february = gameWindow('last-month', '2027-03-10 10:00:00');
    $january = gameWindow('last-month', '2027-01-15 10:00:00');

    expect(gameWindowMoments($march))->toBe(['2027-03-01 00:00:00', '2027-03-31 10:00:00', '2027-02-01 00:00:00', '2027-03-01 00:00:00'])
        ->and($march->text())->toBe('01 Mar — 31 Mar 2027 · compared with 01 Feb — 28 Feb 2027')
        ->and(gameWindowMoments($february))->toBe(['2027-02-01 00:00:00', '2027-03-01 00:00:00', '2027-01-01 00:00:00', '2027-02-01 00:00:00'])
        ->and(gameWindowMoments($january))->toBe(['2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-11-01 00:00:00', '2026-12-01 00:00:00']);
});

it('compares this month on its first day as today alone: with the day before up to the same time', function () {
    expect(gameWindow('this-month', '2026-12-01 08:00:00')->text())->toBe('01 Dec 2026 · compared with 30 Nov 2026 up to the same time');
});

it('compares all time, and days that ask for nothing, with nothing; and nothing once the days before are no longer kept', function () {
    $now = CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC');
    $all = GameAnalyticsWindow::allTime($now, []);
    $sinceTheFirstVisit = GameAnalyticsWindow::days($now->setDate(2026, 11, 5)->startOfDay(), $now->addDay()->startOfDay(), $now, 400, 'all time', [], GameAnalyticsWindow::NOTHING);
    // Last month against September, of which only the last days are still kept.
    $gone = gameWindow('last-month', keptDays: 70);

    expect([$all->before, $all->allTime, $all->text()])->toBe([null, true, 'All time · nothing earlier to compare with'])
        ->and(gameWindowMoments($sinceTheFirstVisit))->toBe(['2026-11-05 00:00:00', '2026-11-19 10:00:00', null, null])
        ->and([$sinceTheFirstVisit->allTime, $sinceTheFirstVisit->beforeGone, $sinceTheFirstVisit->text()])->toBe([false, false, '05 Nov — 19 Nov 2026 · nothing earlier to compare with'])
        ->and([$gone->before, $gone->beforeGone, $gone->text()])->toBe([null, true, '01 Oct — 31 Oct 2026 · the days before are no longer kept, nothing to compare with']);
});

it('names what each preset is compared with', function () {
    expect(array_map(GameAnalyticsWindow::comparisonOf(...), array_keys(ReportPeriod::PRESETS)))->toBe([
        GameAnalyticsWindow::SAME_LENGTH, GameAnalyticsWindow::SAME_LENGTH, GameAnalyticsWindow::SAME_LENGTH, GameAnalyticsWindow::SAME_LENGTH, GameAnalyticsWindow::SAME_LENGTH,
        GameAnalyticsWindow::MONTH_BEFORE, GameAnalyticsWindow::MONTH_BEFORE, GameAnalyticsWindow::NOTHING,
    ]);
});
