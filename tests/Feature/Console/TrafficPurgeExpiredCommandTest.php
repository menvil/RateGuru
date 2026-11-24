<?php

use App\Enums\TrafficDevice;
use App\Models\AccountAttribution;
use App\Models\AnalyticsCell;
use App\Models\AnalyticsCellAccount;
use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsFirstPostCell;
use App\Models\AnalyticsGameDay;
use App\Models\AnalyticsPerson;
use App\Models\AnalyticsPid;
use App\Models\AnalyticsVisit;
use App\Models\TrafficChanceRun;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\TrafficVisitPost;
use App\Models\UserActivityDay;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Support\Analytics\AnalyticsVisitRow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * traffic:purge-expired deletes what the tracker recorded past its retention
 * — visits after 400 days with their conversions, a click's details, the
 * posts of a visit and the outbox after 90, spend figures after 400 — in
 * small batches, and nothing younger; an account credited to a purged visit
 * keeps what Retention reads of it.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-17 03:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('keeps visits for 400 days and a click\'s details and its postbacks for 90, without a new environment variable', function () {
    expect(config('traffic.retention_days'))->toBe(['visits' => 400, 'visit_details' => 90, 'postbacks' => 90, 'spend_days' => 400, 'block_events' => 400])
        ->and(file_get_contents(config_path('traffic.php')))->not->toContain('env(');
});

it('deletes what is past its retention and keeps the rest', function () {
    $old = TrafficVisit::factory()->create(['arrived_at' => now()->subDays(401)]);
    $kept = TrafficVisit::factory()->create(['arrived_at' => now()->subDays(100)]);
    TrafficConversion::factory()->create(['visit_id' => $old->id]);
    $keptConversion = TrafficConversion::factory()->create(['visit_id' => $kept->id]);
    TrafficVisitDetail::query()->forceCreate(['visit_id' => $kept->id, 'landing_path' => '/', 'created_at' => now()->subDays(91)]);
    $fresh = TrafficVisit::factory()->create();
    TrafficVisitDetail::query()->forceCreate(['visit_id' => $fresh->id, 'landing_path' => '/', 'created_at' => now()->subDays(10)]);
    $oldPostback = TrafficPostback::factory()->create(['conversion_id' => $keptConversion->id, 'created_at' => now()->subDays(91)]);
    TrafficSpendDay::factory()->create(['network_day' => '2025-10-01']);
    $keptDay = TrafficSpendDay::factory()->create(['network_day' => '2026-11-01']);

    $this->artisan('traffic:purge-expired', ['--chunk' => 1])->assertSuccessful();

    expect(TrafficVisit::query()->pluck('id')->sort()->values()->all())->toBe([$kept->id, $fresh->id])
        ->and(TrafficConversion::query()->pluck('id')->all())->toBe([$keptConversion->id])
        ->and(TrafficVisitDetail::query()->pluck('visit_id')->all())->toBe([$fresh->id])
        ->and(TrafficPostback::query()->find($oldPostback->id))->toBeNull()
        ->and(TrafficSpendDay::query()->pluck('id')->all())->toBe([$keptDay->id]);
});

it('deletes the cells of people and the sums of game days with their days’ visits, and a browser’s number once no visit names it, never the highest', function () {
    $cell = fn (string $day): array => ['level' => 'device', 'network_day' => $day, 'device' => 'mobile'];
    AnalyticsCell::query()->insert([$cell('2025-10-01'), $cell('2026-11-01')]);
    AnalyticsCellAccount::query()->insert([[...$cell('2025-10-01'), 'signup_day' => '2025-10-01'], [...$cell('2026-11-01'), 'signup_day' => '2026-11-01']]);
    $kept = TrafficVisit::factory()->create(['arrived_at' => now()->subDays(100)]);
    AnalyticsVisit::query()->forceCreate(['id' => $kept->id, 'visitor_key' => 11, 'network_day' => '2026-08-09', 'arrived_at' => $kept->arrived_at, 'last_seen_at' => $kept->arrived_at, 'channel' => 'direct', 'device' => 'mobile']);
    // 12's visits are gone; 13 has none either, but the next number counts on from it.
    AnalyticsPid::query()->insert([['visitor_key' => 11, 'pid' => 1], ['visitor_key' => 12, 'pid' => 2], ['visitor_key' => 13, 'pid' => 3]]);

    $gameDay = fn (string $day): array => ['level' => 'all', 'network_day' => $day, 'arrival_day' => $day, 'game_id' => 1, 'channel' => 'direct', 'device' => 'mobile', 'first_at' => $day.' 10:00:00'];
    AnalyticsGameDay::query()->insert([$gameDay('2025-10-01'), $gameDay('2026-11-01')]);

    $this->artisan('traffic:purge-expired', ['--chunk' => 1])->assertSuccessful()
        ->expectsOutput('Deleted 1 cells of people.')
        ->expectsOutput('Deleted 1 sums of game days.')
        ->expectsOutput('Deleted 1 browser numbers.');

    expect(AnalyticsCell::query()->pluck('network_day')->all())->toBe(['2026-11-01'])
        ->and(AnalyticsCellAccount::query()->pluck('network_day')->all())->toBe(['2026-11-01'])
        ->and(AnalyticsPid::query()->orderBy('pid')->pluck('pid')->all())->toBe([1, 3])
        ->and(AnalyticsGameDay::query()->pluck('network_day')->all())->toBe(['2026-11-01']);
});

it('moves a browser’s first day on to the earliest of its visits left, so the rows count who came back as the visits do', function () {
    // Each first came on a day whose visits go.
    foreach (['ann', 'bob', 'cid', 'dan', 'eve'] as $browser) {
        analyticsVisit($browser, ['network_day' => '2025-10-01']);
    }

    // Ann on two days of the week, Bob once in it and once before it, Cid once in it only, Dan never again — and Eve
    // on a network day before the cutoff's, in a time zone behind UTC, arriving after it.
    analyticsVisit('ann', ['network_day' => '2026-11-14']);
    analyticsVisit('ann', ['network_day' => '2026-11-16']);
    analyticsVisit('bob', ['network_day' => '2026-10-01']);
    analyticsVisit('bob', ['network_day' => '2026-11-16']);
    analyticsVisit('cid', ['network_day' => '2026-11-16']);
    analyticsVisit('eve', ['network_day' => '2025-10-12', 'arrived_at' => '2025-10-13 05:00:00']);
    fillAnalyticsRollup();

    $this->artisan('traffic:purge-expired', ['--chunk' => 1])->assertSuccessful();

    // The rows are read as they are, not filled again: only the next read of a day would put a browser back.
    $returning = function (bool $rows): int {
        config(['analytics.rollup.read' => $rows, 'analytics.rollup.cells' => false]);
        app()->forgetScopedInstances();

        return app(AnalyticsVisitsQuery::class)->returning(analyticsFilter('7d'));
    };
    $first = fn (string $browser): ?string => AnalyticsPerson::query()->find(AnalyticsVisitRow::key(hash('sha256', $browser)))?->first_day;

    expect($returning(false))->toBe(2)
        ->and($returning(true))->toBe(2)
        ->and([$first('ann'), $first('bob'), $first('cid'), $first('dan'), $first('eve')])->toBe(['2026-11-14', '2026-10-01', '2026-11-16', null, '2025-10-12']);
});

it('lets go of the post a session landed on after 90 days, the visit staying and its day read again', function () {
    $old = TrafficVisit::factory()->create(['arrived_at' => now()->subDays(91), 'network_day' => now()->subDays(91)->toDateString(), 'landing_post_id' => 11]);
    $fresh = TrafficVisit::factory()->create(['landing_post_id' => 12]);

    $this->artisan('traffic:purge-expired', ['--chunk' => 1])->assertSuccessful()->expectsOutput('Deleted 1 landing posts of visits.');

    expect($old->fresh()->landing_post_id)->toBeNull()
        ->and($fresh->fresh()->landing_post_id)->toBe(12)
        ->and(AnalyticsDirtyDay::query()->pluck('day')->map(fn (mixed $day): string => substr((string) $day, 0, 10))->all())->toContain(now()->subDays(91)->toDateString());
});

it('deletes the posts a visit opened after 90 days from the first, the visit staying', function () {
    $visit = TrafficVisit::factory()->create(['arrived_at' => now()->subDays(91), 'network_day' => now()->subDays(91)->toDateString()]);
    $fresh = TrafficVisit::factory()->create();
    // The first of a visit's posts opened just before the 90 days were up, the second just after.
    TrafficVisitPost::query()->forceCreate(['visit_id' => $visit->id, 'post_id' => 11, 'views' => 2, 'first_at' => now()->subDays(90)->subMinute()]);
    TrafficVisitPost::query()->forceCreate(['visit_id' => $visit->id, 'post_id' => 12, 'first_at' => now()->subDays(90)->addMinute()]);
    TrafficVisitPost::query()->forceCreate(['visit_id' => $fresh->id, 'post_id' => 11, 'first_at' => now()->subDay()]);

    $this->artisan('traffic:purge-expired', ['--chunk' => 1])->assertSuccessful()->expectsOutput('Deleted 1 posts of visits.');

    expect(TrafficVisitPost::query()->orderBy('visit_id')->orderBy('post_id')->get(['visit_id', 'post_id'])->map(fn (TrafficVisitPost $row): array => [$row->visit_id, $row->post_id])->all())
        ->toBe([[$visit->id, 12], [$fresh->id, 11]])
        ->and(TrafficVisit::query()->whereKey($visit->id)->exists())->toBeTrue()
        // Analytics keeps what each visit did with posts: the visit's day is read again.
        ->and(AnalyticsDirtyDay::query()->pluck('day')->all())->toContain(substr((string) $visit->network_day, 0, 10));
});

it('deletes the days members did something after 400 days, without a new environment variable', function () {
    $old = UserActivityDay::factory()->create(['day' => '2025-10-12']);
    $kept = UserActivityDay::factory()->create(['user_id' => $old->user_id, 'day' => '2025-10-13']);

    $this->artisan('traffic:purge-expired', ['--chunk' => 1])->assertSuccessful()->expectsOutput('Deleted 1 activity days.');

    expect(config('analytics.retention_days.activity'))->toBe(400)
        ->and(file_get_contents(config_path('analytics.php')))->not->toContain('env(')
        ->and(UserActivityDay::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('deletes nothing when a retention is not a positive number of days', function () {
    config(['traffic.retention_days.visits' => 0]);
    TrafficVisit::factory()->create(['arrived_at' => now()->subDays(900)]);

    $this->artisan('traffic:purge-expired')->assertFailed();

    expect(TrafficVisit::query()->count())->toBe(1);

    config(['traffic.retention_days.visits' => 400, 'analytics.retention_days.activity' => 'a year']);
    UserActivityDay::factory()->create(['day' => '2020-01-01']);

    $this->artisan('traffic:purge-expired')->assertFailed();

    expect(TrafficVisit::query()->count())->toBe(1)
        ->and(UserActivityDay::query()->count())->toBe(1);
});

it('runs every day without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'traffic:purge-expired'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('keeps the newest chance working-outs, and one begun while it purges', function () {
    config(['traffic.chance_runs_kept' => 2]);
    $run = fn () => TrafficChanceRun::query()->forceCreate(['status' => 'finished', 'reason' => 'nightly', 'price_set_id' => TrafficPriceSet::current()->id, 'matured_before' => now(), 'started_at' => now(), 'finished_at' => now()]);
    $runs = collect(range(1, 4))->map(fn () => $run()->id);
    $begun = null;
    $started = false;
    // A working-out starts right after the purge has read which runs to keep.
    DB::listen(function (QueryExecuted $query) use (&$begun, &$started, $run): void {
        if (! $started && str_contains($query->sql, 'traffic_chance_runs') && str_contains(strtolower($query->sql), 'desc')) {
            $started = true;
            $begun = $run()->id;
        }
    });

    $this->artisan('traffic:purge-expired')->assertSuccessful()->expectsOutput('Deleted 2 chance runs.');

    expect(TrafficChanceRun::query()->orderBy('id')->pluck('id')->all())->toBe([$runs[2], $runs[3], $begun]);
});

it('marks the days of the visits credited with an account in the sessions whose opened posts go, and deletes their first posts’ cells with their days’ visits', function () {
    $day = fn (int $daysAgo): array => ['arrived_at' => now()->subDays($daysAgo), 'network_day' => now()->subDays($daysAgo)->toDateString()];
    // A session whose first post goes, credited on a later day; another whose post goes only voted on, never opened.
    $entry = TrafficVisit::factory()->create($day(92));
    $credited = TrafficVisit::factory()->create([...$day(85), 'entry_visit_id' => $entry->id, 'account_at' => now()->subDays(85)]);
    $entry->forceFill(['entry_visit_id' => $entry->id])->save();
    $voted = TrafficVisit::factory()->create($day(93));
    $votedCredited = TrafficVisit::factory()->create([...$day(80), 'entry_visit_id' => $voted->id, 'account_at' => now()->subDays(80)]);
    $voted->forceFill(['entry_visit_id' => $voted->id])->save();
    TrafficVisitPost::query()->forceCreate(['visit_id' => $entry->id, 'post_id' => 11, 'views' => 1, 'first_at' => now()->subDays(91)]);
    TrafficVisitPost::query()->forceCreate(['visit_id' => $voted->id, 'post_id' => 12, 'views' => 0, 'voted' => true, 'first_at' => now()->subDays(91)]);
    AnalyticsFirstPostCell::query()->insert([
        ['network_day' => '2025-10-01', 'device' => 'mobile', 'post_id' => 11],
        ['network_day' => '2026-11-01', 'device' => 'mobile', 'post_id' => null],
    ]);

    $this->artisan('traffic:purge-expired', ['--chunk' => 1])->assertSuccessful()->expectsOutput('Deleted 1 cells of first posts.');

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())
        ->toContain(substr((string) $credited->network_day, 0, 10))
        ->not->toContain(substr((string) $votedCredited->network_day, 0, 10))
        ->and(AnalyticsFirstPostCell::query()->pluck('network_day')->all())->toBe(['2026-11-01']);
});

it('marks the days of the posts it deleted even when a later purge of the same run fails', function () {
    $day = fn (int $daysAgo): array => ['arrived_at' => now()->subDays($daysAgo), 'network_day' => now()->subDays($daysAgo)->toDateString()];
    $entry = TrafficVisit::factory()->create($day(92));
    $credited = TrafficVisit::factory()->create([...$day(85), 'entry_visit_id' => $entry->id, 'account_at' => now()->subDays(85)]);
    $entry->forceFill(['entry_visit_id' => $entry->id])->save();
    TrafficVisitPost::query()->forceCreate(['visit_id' => $entry->id, 'post_id' => 11, 'views' => 1, 'first_at' => now()->subDays(91)]);
    // The posts go, then the outbox's purge fails: the next run finds no post left to tell it the days.
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'traffic_postbacks')) {
            throw new RuntimeException('The outbox could not be purged.');
        }
    });

    expect(fn () => Artisan::call('traffic:purge-expired', ['--chunk' => 1]))->toThrow(RuntimeException::class, 'The outbox could not be purged.')
        ->and(TrafficVisitPost::query()->count())->toBe(0)
        ->and(AnalyticsDirtyDay::query()->pluck('day')->all())
        ->toContain(substr((string) $entry->network_day, 0, 10))
        ->toContain(substr((string) $credited->network_day, 0, 10));
});

it('keeps what Retention reads of a purged visit on the account credited to it, the pointer let go', function () {
    $old = TrafficVisit::factory()->create(['arrived_at' => now()->subDays(401), 'source_key' => 'reddit:organic', 'device' => 'desktop']);
    $attribution = AccountAttribution::factory()->confirmed()->create(['credited_visit_id' => $old->id, 'signed_up_at' => now()->subDays(401)]);

    $this->artisan('traffic:purge-expired')->assertSuccessful();

    expect(TrafficVisit::query()->find($old->id))->toBeNull()
        ->and($attribution->fresh())
        ->credited_visit_id->toBeNull()
        ->credited_source_key->toBe('reddit:organic')
        ->credited_device->toBe(TrafficDevice::Desktop);
});
