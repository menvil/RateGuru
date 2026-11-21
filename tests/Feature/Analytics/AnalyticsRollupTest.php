<?php

use App\Actions\Analytics\MarkAnalyticsDayAction;
use App\Actions\Analytics\RefreshAnalyticsDayAction;
use App\Actions\Analytics\RefreshAnalyticsVisitsAction;
use App\Actions\Traffic\AllocateSpendDayAction;
use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Enums\TrafficEvent;
use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsPerson;
use App\Models\AnalyticsRollupState;
use App\Models\AnalyticsVisit;
use App\Models\TrafficSource;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Support\Analytics\AnalyticsVisitRow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Analytics' own rows of the visits (analytics_visits): a day refreshed
 * writes a row a visit and, again, only the rows of the visits that changed;
 * each browser's first day the earliest its page ran; an older day told it
 * changed read again and let go; the days filled
 * from the first visit's on, as far as time allows, then counted as filled;
 * read only once filled, recently brought up to date and allowed; gone with
 * their visit; refreshed every minute and rechecked at night.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

it('writes a row a visit, then only the rows of the visits that changed', function () {
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-10', 'votes' => 2]);
    analyticsVisit('bob', ['network_day' => '2026-11-10']);
    analyticsVisit('cid', ['network_day' => '2026-11-11']);
    $refresh = app(RefreshAnalyticsDayAction::class);

    expect($refresh->handle('2026-11-10'))->toBe(2)
        ->and(AnalyticsVisit::query()->count())->toBe(2)
        ->and(AnalyticsVisit::query()->find($ann->id))->visitor_key->toBe(AnalyticsVisitRow::key(hash('sha256', 'ann')))->votes->toBe(2)->opened->toBeTrue()->started->toBeFalse()
        ->and($refresh->handle('2026-11-10'))->toBe(0);

    $ann->forceFill(['started_at' => '2026-11-10 10:02:00'])->save();

    expect($refresh->handle('2026-11-10'))->toBe(1)
        ->and(AnalyticsVisit::query()->find($ann->id)->started)->toBeTrue();
});

it('keeps each browser’s first day the earliest its page ran, whatever order the days are read in', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-12']);
    analyticsVisit('ann', ['network_day' => '2026-11-05']);
    analyticsVisit('bob', ['network_day' => '2026-11-04', 'opened_at' => null]);
    analyticsVisit('bob', ['network_day' => '2026-11-12']);
    $refresh = app(RefreshAnalyticsDayAction::class);
    $first = fn (string $browser): ?string => AnalyticsPerson::query()->find(AnalyticsVisitRow::key(hash('sha256', $browser)))?->first_day;

    $refresh->handle('2026-11-12');

    expect([$first('ann'), $first('bob')])->toBe(['2026-11-12', '2026-11-12']);

    $refresh->handle('2026-11-05');
    $refresh->handle('2026-11-04');
    $refresh->handle('2026-11-12');

    // A visit whose page never ran is no first day.
    expect([$first('ann'), $first('bob')])->toBe(['2026-11-05', '2026-11-12']);
});

it('marks an older day changed with a new token each time, and never a day read every minute anyway', function () {
    $mark = app(MarkAnalyticsDayAction::class);

    $mark->handle('2026-11-18');
    $mark->handle('2026-11-17 23:59:59');
    $mark->handle(null);
    $mark->handle('not a day');

    expect(AnalyticsDirtyDay::query()->count())->toBe(0);

    $mark->handle('2026-11-02 09:00:00');
    $token = AnalyticsDirtyDay::query()->find('2026-11-02')->token;
    $mark->handle('2026-11-02');

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe(['2026-11-02'])
        ->and(AnalyticsDirtyDay::query()->find('2026-11-02')->token)->not->toBe($token);
});

it('marks the day of a visit days old that a late step, a spend shared out again or a click that came back changes', function () {
    $old = analyticsVisit('ann', ['network_day' => '2026-11-03']);
    $today = analyticsVisit('bob', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 10:00:00']);
    $source = TrafficSource::factory()->create();

    app(MarkTrafficVisitAction::class)->handle($today->id, TrafficEvent::Completed, now()->toImmutable());

    // A day read every minute anyway is never marked.
    expect(AnalyticsDirtyDay::query()->count())->toBe(0);

    app(MarkTrafficVisitAction::class)->handle($old->id, TrafficEvent::Account, now()->toImmutable(), ['signup_at' => now()]);
    app(AllocateSpendDayAction::class)->handle($source, '2026-11-05', now()->toImmutable());

    expect(AnalyticsDirtyDay::query()->orderBy('day')->pluck('day')->all())->toBe(['2026-11-03', '2026-11-05']);
});

it('reads a marked day again and lets it go', function () {
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-02']);
    analyticsVisit('bob', ['network_day' => '2026-11-03']);
    fillAnalyticsRollup();

    $ann->forceFill(['account_at' => '2026-11-09 08:00:00', 'signup_at' => '2026-11-02 10:05:00'])->save();
    app(MarkAnalyticsDayAction::class)->handle('2026-11-02');

    $done = app(RefreshAnalyticsVisitsAction::class)->handle(microtime(true) + 30);

    expect(AnalyticsVisit::query()->find($ann->id))->account->toBeTrue()->signup_at->toDateTimeString()->toBe('2026-11-02 10:05:00')
        ->and(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe([])
        ->and($done['filled'])->toBeTrue();
});

it('fills the days from the first visit’s on as far as time allows, and counts them filled once they reach the days read every minute', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-10', 'arrived_at' => '2026-11-10 10:00:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-13']);
    analyticsVisit('cid', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 11:00:00']);
    $refresh = app(RefreshAnalyticsVisitsAction::class);

    // No time left: only the days read every minute.
    $refresh->handle(microtime(true) - 1);

    expect(AnalyticsVisit::query()->pluck('network_day')->all())->toBe(['2026-11-18'])
        ->and(AnalyticsRollupState::current())->filled_at->toBeNull()->refreshed_at->not->toBeNull();

    $done = $refresh->handle(microtime(true) + 30);

    expect($done['filled'])->toBeTrue()
        ->and(AnalyticsVisit::query()->orderBy('network_day')->pluck('network_day')->all())->toBe(['2026-11-10', '2026-11-13', '2026-11-18'])
        ->and(AnalyticsRollupState::current())->filled_through->toBe('2026-11-16')->filled_at->not->toBeNull();
});

it('reads today’s day and later every minute, and the earlier days that still change every fifth minute', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 10:00:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 10:00:00']);
    $refresh = app(RefreshAnalyticsVisitsAction::class);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:01:00'));
    $refresh->handle(microtime(true) - 1, now: CarbonImmutable::now());

    expect(AnalyticsVisit::query()->pluck('network_day')->all())->toBe(['2026-11-18']);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:05:00'));
    $refresh->handle(microtime(true) - 1, now: CarbonImmutable::now());

    expect(AnalyticsVisit::query()->orderBy('network_day')->pluck('network_day')->all())->toBe(['2026-11-17', '2026-11-18']);
});

it('reads the days given back from today again when asked to recheck them', function () {
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-01']);
    fillAnalyticsRollup();
    // Changed without telling: only a recheck reaching back that far sees it.
    $ann->forceFill(['completed_at' => '2026-11-01 10:09:00'])->save();
    $refresh = app(RefreshAnalyticsVisitsAction::class);

    $refresh->handle(microtime(true) + 30, 10);

    expect(AnalyticsVisit::query()->find($ann->id)->completed)->toBeFalse();

    $refresh->handle(microtime(true) + 30, 20);

    expect(AnalyticsVisit::query()->find($ann->id)->completed)->toBeTrue();
});

it('reads Analytics’ own rows only once filled, brought up to date recently and allowed', function () {
    $reads = function (): bool {
        app()->forgetScopedInstances();

        return app(AnalyticsRollupGate::class)->reads();
    };

    expect($reads())->toBeFalse();

    fillAnalyticsRollup();

    expect($reads())->toBeTrue();

    config(['analytics.rollup.read' => false]);

    expect($reads())->toBeFalse();

    config(['analytics.rollup.read' => true]);
    $this->travel(31)->minutes();

    expect($reads())->toBeFalse();
});

it('goes with its visit', function () {
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-10']);
    fillAnalyticsRollup();

    $ann->delete();

    expect(AnalyticsVisit::query()->count())->toBe(0);
});

it('runs every minute and rechecks the last days at night, the two never at once', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'analytics:refresh-visits'))
        ->values();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('expression')->all())->toBe(['* * * * *', '25 3 * * *'])
        ->and($events->every(fn ($event) => $event->withoutOverlapping && $event->mutexName() === 'analytics-refresh-visits'))->toBeTrue()
        ->and($events[1]->command)->toContain('--recheck=60');
});

it('refuses a recheck or a time it can’t read, before writing anything', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-18']);

    $this->artisan('analytics:refresh-visits', ['--recheck' => 'all'])->assertFailed();
    $this->artisan('analytics:refresh-visits', ['--seconds' => '0'])->assertFailed();

    expect(AnalyticsVisit::query()->count())->toBe(0);

    $this->artisan('analytics:refresh-visits')->assertSuccessful();

    expect(AnalyticsVisit::query()->count())->toBe(1);
});
