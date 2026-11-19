<?php

use App\Models\TrafficChanceRun;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\TrafficVisitPost;
use App\Models\UserActivityDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * traffic:purge-expired deletes what the tracker recorded past its retention
 * — visits after 400 days with their conversions, a click's details, the
 * posts of a visit and the outbox after 90, spend figures after 400 — in
 * small batches, and nothing younger.
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

it('deletes the posts a visit opened after 90 days from the first, the visit staying', function () {
    $visit = TrafficVisit::factory()->create(['arrived_at' => now()->subDays(91)]);
    $fresh = TrafficVisit::factory()->create();
    // The first of a visit's posts opened just before the 90 days were up, the second just after.
    TrafficVisitPost::query()->forceCreate(['visit_id' => $visit->id, 'post_id' => 11, 'views' => 2, 'first_at' => now()->subDays(90)->subMinute()]);
    TrafficVisitPost::query()->forceCreate(['visit_id' => $visit->id, 'post_id' => 12, 'first_at' => now()->subDays(90)->addMinute()]);
    TrafficVisitPost::query()->forceCreate(['visit_id' => $fresh->id, 'post_id' => 11, 'first_at' => now()->subDay()]);

    $this->artisan('traffic:purge-expired', ['--chunk' => 1])->assertSuccessful()->expectsOutput('Deleted 1 posts of visits.');

    expect(TrafficVisitPost::query()->orderBy('visit_id')->orderBy('post_id')->get(['visit_id', 'post_id'])->map(fn (TrafficVisitPost $row): array => [$row->visit_id, $row->post_id])->all())
        ->toBe([[$visit->id, 12], [$fresh->id, 11]])
        ->and(TrafficVisit::query()->whereKey($visit->id)->exists())->toBeTrue();
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
