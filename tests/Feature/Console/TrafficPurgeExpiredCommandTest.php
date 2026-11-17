<?php

use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

/**
 * traffic:purge-expired deletes what the tracker recorded past its retention
 * — visits after 400 days with their conversions, a click's details and the
 * outbox after 90, spend figures after 400 — in small batches, and nothing
 * younger.
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

it('deletes nothing when a retention is not a positive number of days', function () {
    config(['traffic.retention_days.visits' => 0]);
    TrafficVisit::factory()->create(['arrived_at' => now()->subDays(900)]);

    $this->artisan('traffic:purge-expired')->assertFailed();

    expect(TrafficVisit::query()->count())->toBe(1);
});

it('runs every day without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'traffic:purge-expired'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
