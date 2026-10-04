<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules both content cleanup commands daily without overlapping', function (string $command) {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', $command))
        ->values();

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('0 0 * * *') // ->daily()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->command)->not->toContain('--older-than')
        ->and($event->command)->not->toContain('--force');
})->with(['comments:purge-deleted', 'moderation:purge-content']);

it('keeps the existing posts and media purge schedules unchanged', function () {
    $schedule = app(Schedule::class);

    foreach (['posts:purge', 'media:purge'] as $command) {
        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', $command))
            ->values();

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 0 * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue();
    }
});
