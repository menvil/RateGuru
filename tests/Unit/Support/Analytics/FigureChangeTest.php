<?php

use App\Support\Analytics\FigureChange;

/*
 * How a figure moved against the window before its period: up or down by
 * whole percent of what it was, the same below half a percent, no earlier
 * data when the window before counted none, and nothing at all without a
 * window before.
 */

it('says by how much a figure went up or down against the window before', function () {
    $up = FigureChange::between(118, 100, 'the previous 30 days');
    $down = FigureChange::between(96, 100, 'the day before');

    expect([$up?->text, $up?->direction])->toBe(['+18% vs the previous 30 days', FigureChange::UP])
        ->and([$down?->text, $down?->direction])->toBe(['−4% vs the day before', FigureChange::DOWN])
        ->and(FigureChange::between(2500, 100, 'the previous 7 days')?->text)->toBe('+2,400% vs the previous 7 days')
        ->and(FigureChange::between(0, 40, 'the previous 7 days')?->text)->toBe('−100% vs the previous 7 days')
        ->and(FigureChange::between(0.125, 0.1, 'the previous 7 days')?->text)->toBe('+25% vs the previous 7 days');
});

it('calls a move of less than half a percent the same', function () {
    $same = FigureChange::between(1004, 1000, 'the previous 90 days');

    expect([$same?->text, $same?->direction])->toBe(['Same as the previous 90 days', FigureChange::SAME])
        ->and(FigureChange::between(1005, 1000, 'the previous 90 days')?->text)->toBe('+1% vs the previous 90 days');
});

it('has no earlier data when the window before counted none, and nothing without a window before', function () {
    $none = FigureChange::between(12, 0, 'the previous 30 days');

    expect([$none?->text, $none?->direction])->toBe(['No earlier data', FigureChange::NO_EARLIER_DATA])
        ->and(FigureChange::between(0, 0, 'the previous 30 days')?->text)->toBe('No earlier data')
        ->and(FigureChange::between(12, 10, null))->toBeNull()
        ->and(FigureChange::between(12, null, 'the previous 30 days'))->toBeNull();
});
