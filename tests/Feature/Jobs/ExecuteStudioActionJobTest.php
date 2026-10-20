<?php

use App\Enums\StudioActionStatus;
use App\Jobs\Studio\ExecuteStudioActionJob;
use Illuminate\Support\Facades\Log;

/**
 * The job that runs one sent Content Studio action: the action's id and
 * nothing else, inside the queue's timings, never retried by the queue — and
 * when it dies, it only says so, leaving the action for recovery.
 */
it('runs within the queue\'s timings, once, failing on its timeout', function () {
    $job = new ExecuteStudioActionJob(1);

    expect($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBe(75)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        // A running action is taken back only once a live run could no longer hold it.
        ->and(config('content_studio.actions.running_stale_after_seconds'))->toBeGreaterThan(config('queue.connections.redis.retry_after'));
});

it('carries the action\'s id and nothing else', function () {
    $job = new ExecuteStudioActionJob(42);

    expect($job->actionId)->toBe(42)
        ->and(serialize($job))->not->toContain('App\\Models');
});

it('only logs when it dies, settling nothing: the action is left for recovery', function () {
    config(['queue.default' => 'database']);
    switchStudioExecution(true);
    $action = approvedStudioAction();
    dispatchDueStudioActions();
    $action->fresh()->forceFill(['status' => StudioActionStatus::Running, 'claimed_at' => now()])->save();
    Log::spy();

    (new ExecuteStudioActionJob($action->id))->failed(new RuntimeException('The worker was killed.'));

    expect($action->fresh())->status->toBe(StudioActionStatus::Running)->attempts->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'studio.action.job_failed'
        && $context['studio_action_id'] === $action->id
        && $context['exception'] === RuntimeException::class);
});
