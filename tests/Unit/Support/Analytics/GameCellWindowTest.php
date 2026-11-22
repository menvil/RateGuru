<?php

use App\Support\Analytics\GameCellDays;
use App\Support\Analytics\GameCellWindow;
use Carbon\CarbonImmutable;

/*
 * The days a game's people are counted by (GameCellDays): a day open until
 * an hour after it ends, counted whole ten minutes later, the days after it
 * up to ten minutes ago. And a window of a game's figures as what those
 * cells cover and what only the events and attempts can give
 * (GameCellWindow): the days counted whole, the open days up to their moment
 * while the window starts no later than they do and reaches the moment, the
 * days changed since they were counted read live like the part days at the
 * ends and the time since the moment, and the open days' plays begun before
 * it, whose later changes are read live too.
 */

/** @return list<array{0: string, 1: string}> stretches as their moments */
function cellStretches(array $stretches): array
{
    return array_map(fn (array $stretch): array => [$stretch[0]->toDateTimeString(), $stretch[1]->toDateTimeString()], $stretches);
}

function cellWindow(string $from, string $to, string $through = '2026-11-17', ?string $moment = '2026-11-18 14:20:00', array $changed = []): GameCellWindow
{
    return GameCellWindow::of(CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($to, 'UTC'), $through, $moment === null ? null : CarbonImmutable::parse($moment, 'UTC'), $changed);
}

it('keeps a day open until an hour after it ends, counts it whole ten minutes later, and the days after up to ten minutes ago', function () {
    $now = fn (string $moment): CarbonImmutable => CarbonImmutable::parse($moment, 'UTC');

    expect(GameCellDays::isOpen('2026-11-17', $now('2026-11-18 00:59:59')))->toBeTrue()
        ->and(GameCellDays::isOpen('2026-11-17', $now('2026-11-18 01:00:00')))->toBeFalse()
        ->and(GameCellDays::lastClosable($now('2026-11-18 01:09:59')))->toBe('2026-11-16')
        ->and(GameCellDays::lastClosable($now('2026-11-18 01:10:00')))->toBe('2026-11-17')
        ->and(GameCellDays::lastClosable($now('2026-11-18 03:10:00+02:00')))->toBe('2026-11-17')
        ->and(GameCellDays::momentOf($now('2026-11-18 14:30:45.700'))->toDateTimeString())->toBe('2026-11-18 14:20:45')
        ->and(GameCellDays::dayOf($now('2026-11-18 00:30:00+01:00')))->toBe('2026-11-17');
});

it('reads a window to now from the days counted whole and the open days up to their moment, the time since live', function () {
    $window = cellWindow('2026-10-20 00:00:00', '2026-11-18 14:30:01');

    expect([$window->closedFrom, $window->closedBefore, $window->openFrom, $window->openThrough, $window->moment?->toDateTimeString()])
        ->toBe(['2026-10-20', '2026-11-18', '2026-11-18', '2026-11-18', '2026-11-18 14:20:00'])
        ->and(cellStretches($window->live))->toBe([['2026-11-18 14:20:00', '2026-11-18 14:30:01']])
        ->and(cellStretches($window->late))->toBe([['2026-11-18 00:00:00', '2026-11-18 14:20:00']])
        ->and($window->readsCells())->toBeTrue();
});

it('reads yesterday still open beside today, both up to the moment', function () {
    $window = cellWindow('2026-11-17 00:00:00', '2026-11-18 00:40:01', through: '2026-11-16', moment: '2026-11-18 00:30:00');

    expect([$window->closedFrom, $window->openFrom, $window->openThrough])->toBe([null, '2026-11-17', '2026-11-18'])
        ->and(cellStretches($window->live))->toBe([['2026-11-18 00:30:00', '2026-11-18 00:40:01']])
        ->and(cellStretches($window->late))->toBe([['2026-11-17 00:00:00', '2026-11-18 00:30:00']]);
});

it('reads the part days at the ends, the days changed since they were counted and a window short of the moment live', function () {
    $parts = cellWindow('2026-11-10 06:00:00', '2026-11-16 18:00:00', changed: ['2026-11-12', '2026-11-30', '2026-11-01']);

    expect([$parts->closedFrom, $parts->closedBefore, $parts->openFrom, $parts->except])->toBe(['2026-11-11', '2026-11-16', null, ['2026-11-12']])
        ->and(cellStretches($parts->live))->toBe([
            ['2026-11-10 06:00:00', '2026-11-11 00:00:00'],
            ['2026-11-12 00:00:00', '2026-11-13 00:00:00'],
            ['2026-11-16 00:00:00', '2026-11-16 18:00:00'],
        ])
        ->and($parts->late)->toBe([]);

    // Short of the moment: the open day is read live, the days counted whole are not.
    $short = cellWindow('2026-11-16 00:00:00', '2026-11-18 14:00:00');

    expect([$short->closedFrom, $short->closedBefore, $short->openFrom])->toBe(['2026-11-16', '2026-11-18', null])
        ->and(cellStretches($short->live))->toBe([['2026-11-18 00:00:00', '2026-11-18 14:00:00']]);

    // An open day changed since its moment's count: read live, its plays' later changes with it.
    $changed = cellWindow('2026-11-17 00:00:00', '2026-11-18 14:30:01', through: '2026-11-16', changed: ['2026-11-18']);

    expect([$changed->openFrom, $changed->except])->toBe(['2026-11-17', ['2026-11-18']])
        ->and(cellStretches($changed->live))->toBe([['2026-11-18 00:00:00', '2026-11-18 14:30:01']])
        ->and(cellStretches($changed->late))->toBe([['2026-11-17 00:00:00', '2026-11-18 00:00:00']]);
});

it('reads today alone, or from a part day before it, from the open day up to the moment, and a window starting later in the open day live', function () {
    $today = cellWindow('2026-11-18 00:00:00', '2026-11-18 14:30:01');

    expect([$today->closedFrom, $today->openFrom, $today->openThrough, $today->moment?->toDateTimeString()])->toBe([null, '2026-11-18', '2026-11-18', '2026-11-18 14:20:00'])
        ->and(cellStretches($today->live))->toBe([['2026-11-18 14:20:00', '2026-11-18 14:30:01']])
        ->and(cellStretches($today->late))->toBe([['2026-11-18 00:00:00', '2026-11-18 14:20:00']])
        ->and($today->readsCells())->toBeTrue();

    // Two part days with no whole one between: the day counted whole is not read, the open day is.
    $since = cellWindow('2026-11-17 06:00:00', '2026-11-18 14:30:01');

    expect([$since->closedFrom, $since->openFrom])->toBe([null, '2026-11-18'])
        ->and(cellStretches($since->live))->toBe([['2026-11-17 06:00:00', '2026-11-18 00:00:00'], ['2026-11-18 14:20:00', '2026-11-18 14:30:01']]);

    // The cells count the open day from its midnight: a window starting after it can't take them.
    $later = cellWindow('2026-11-18 06:00:00', '2026-11-18 14:30:01');

    expect($later->readsCells())->toBeFalse()
        ->and(cellStretches($later->live))->toBe([['2026-11-18 06:00:00', '2026-11-18 14:30:01']]);
});

it('reads no cell for a window within a day that is over, after every day counted with nothing counted up to a moment, or for all time before the first', function () {
    expect(cellWindow('2026-11-10 06:00:00', '2026-11-10 18:00:00')->readsCells())->toBeFalse()
        ->and(cellStretches(cellWindow('2026-11-10 06:00:00', '2026-11-10 18:00:00')->live))->toBe([['2026-11-10 06:00:00', '2026-11-10 18:00:00']])
        ->and(cellWindow('2026-11-18 00:00:00', '2026-11-18 14:30:01', moment: null)->readsCells())->toBeFalse()
        // From the epoch: every day up to the last counted whole is covered, whatever its number.
        ->and(cellWindow('1970-01-01 00:00:00', '2026-11-18 14:30:01')->closedFrom)->toBe('1970-01-01');
});
