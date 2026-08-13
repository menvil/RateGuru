<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules posts:purge daily without overlapping and with no destructive overrides', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'posts:purge'))
        ->values();

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('0 0 * * *') // ->daily()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->command)->not->toContain('--older-than')
        ->and($event->command)->not->toContain('--dry-run');
});
