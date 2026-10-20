<?php

use App\Enums\StudioActionStatus;
use App\Jobs\Studio\ExecuteStudioActionJob;
use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Studio\StudioExecutionSwitch;
use Illuminate\Console\Scheduling\Schedule;

/**
 * `studio:dispatch-due`, run by the scheduler every minute through the
 * existing `schedule:run` — and off on every installation until switched
 * on, so the first deploy publishes nothing.
 */
it('is scheduled every minute, never overlapping itself, its lock lasting minutes rather than a day', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'studio:dispatch-due'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        // A scheduler killed mid-run leaves the lock behind: it must lapse well before Laravel's default 1440 minutes.
        ->and($events->first()->expiresAt)->toBe(5);
});

it('is off on an installation that never switched it on: a run sends and changes nothing', function () {
    config(['queue.default' => 'database']);
    $action = approvedStudioAction();

    expect(app(ProjectSettingsManager::class)->defaults()['feature_flags'][StudioExecutionSwitch::FLAG])->toBeFalse()
        ->and(ProjectSettings::query()->exists())->toBeFalse()
        ->and(app(StudioExecutionSwitch::class)->isOn())->toBeFalse();

    $this->artisan('studio:dispatch-due')
        ->expectsOutput('Content Studio execution is switched off: nothing was sent.')
        ->assertSuccessful();

    expect($action->fresh())->status->toBe(StudioActionStatus::Pending)->attempts->toBe(0)
        ->and(queuedJobs(ExecuteStudioActionJob::class))->toBe([]);
});

it('says what it sent once switched on', function () {
    config(['queue.default' => 'database']);
    switchStudioExecution(true);
    approvedStudioAction();

    $this->artisan('studio:dispatch-due')
        ->expectsOutput('Sent 1, sent again 0, given up on 0.')
        ->assertSuccessful();
});
