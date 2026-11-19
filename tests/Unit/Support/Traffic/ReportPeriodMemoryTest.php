<?php

use App\Models\User;
use App\Support\Traffic\Reports\ReportPeriodMemory;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The period an administrator last chose in a report page's picker, kept in
 * their session: recalled for an address that names no period — never for
 * one that names any, read or not — each picker's and each administrator's
 * apart, and nothing for nobody signed in or for what isn't a period as the
 * address would name it.
 */

function rememberingAdministrator(int $id): void
{
    test()->actingAs((new User)->forceFill(['id' => $id]));
}

it('recalls the period last kept for a picker while the address names none', function () {
    rememberingAdministrator(7);

    expect(ReportPeriodMemory::recall(Request::create('/admin/analytics'), 'analytics'))->toBeNull();

    ReportPeriodMemory::remember('analytics', 'today', '', '');
    ReportPeriodMemory::remember('traffic-sources', '30d', '2026-11-01', '2026-11-10');

    expect(ReportPeriodMemory::recall(Request::create('/admin/analytics', 'GET', ['tab' => 'geo']), 'analytics'))->toBe(['period' => 'today', 'from' => '', 'to' => ''])
        ->and(ReportPeriodMemory::recall(Request::create('/admin/traffic-sources'), 'traffic-sources'))->toBe(['period' => '30d', 'from' => '2026-11-01', 'to' => '2026-11-10'])
        ->and(ReportPeriodMemory::recall(Request::create('/admin/games/1/analytics'), 'game-analytics'))->toBeNull();

    // The latest kept is the one recalled.
    ReportPeriodMemory::remember('analytics', '7d', '', '');

    expect(ReportPeriodMemory::recall(Request::create('/admin/analytics'), 'analytics'))->toBe(['period' => '7d', 'from' => '', 'to' => '']);
});

it('recalls nothing for an address that names a period of its own, read or not', function (array $query) {
    rememberingAdministrator(7);
    ReportPeriodMemory::remember('analytics', 'today', '', '');

    expect(ReportPeriodMemory::named(Request::create('/admin/analytics', 'GET', $query)))->toBeTrue()
        ->and(ReportPeriodMemory::recall(Request::create('/admin/analytics', 'GET', $query), 'analytics'))->toBeNull();
})->with([
    'a preset' => [['period' => '90d']],
    'the default' => [['period' => '30d']],
    'a range' => [['from' => '2026-11-01', 'to' => '2026-11-10']],
    'one end only' => [['to' => '2026-11-10']],
    'nothing it can read' => [['period' => '']],
]);

it('keeps each administrator’s apart, and nothing for nobody signed in', function () {
    rememberingAdministrator(7);
    ReportPeriodMemory::remember('analytics', 'today', '', '');
    rememberingAdministrator(8);

    expect(ReportPeriodMemory::recall(Request::create('/admin/analytics'), 'analytics'))->toBeNull();

    ReportPeriodMemory::remember('analytics', '90d', '', '');
    rememberingAdministrator(7);

    expect(ReportPeriodMemory::recall(Request::create('/admin/analytics'), 'analytics'))->toBe(['period' => 'today', 'from' => '', 'to' => '']);

    auth()->logout();
    ReportPeriodMemory::remember('analytics', 'yesterday', '', '');

    expect(ReportPeriodMemory::recall(Request::create('/admin/analytics'), 'analytics'))->toBeNull()
        ->and(session()->get(ReportPeriodMemory::SESSION_KEY))->toHaveKeys(['7', '8'])->toHaveCount(2);
});

it('recalls nothing kept that is not a period as an address names it', function (mixed $kept) {
    rememberingAdministrator(7);
    session()->put(ReportPeriodMemory::SESSION_KEY.'.7.analytics', $kept);

    expect(ReportPeriodMemory::recall(Request::create('/admin/analytics'), 'analytics'))->toBeNull();
})->with([
    'text' => ['7d'],
    'a day missing' => [['period' => '30d', 'from' => '2026-11-01']],
    'not text' => [['period' => ['7d'], 'from' => '', 'to' => '']],
]);
