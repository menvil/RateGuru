<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules media:purge daily, without overlapping, and never with --orphans or --force', function () {
    $schedule = app(Schedule::class);

    $mediaPurgeEvents = collect($schedule->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'media:purge'))
        ->values();

    expect($mediaPurgeEvents)->toHaveCount(1);

    $event = $mediaPurgeEvents->first();

    expect($event->expression)->toBe('0 0 * * *') // ->daily()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->command)->not->toContain('--orphans')
        ->and($event->command)->not->toContain('--force');
});
