<?php

namespace App\Jobs\Studio;

use App\Actions\Studio\ExecuteStudioAction;
use App\Support\Observability\DomainLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one sent Content Studio action (ExecuteStudioAction), on the
 * environment's Redis queue. It carries the action's id and nothing else: the
 * action is read from the database, never from the job.
 *
 * Its timings nest inside the queue's: 75 s, under Redis's 90 s retry_after,
 * so Laravel ends it before Redis hands it to another worker; never retried
 * by the queue (`tries` 1). Delivered twice, the second run finds nothing to
 * claim. Should it die mid-run — a timeout, a lost connection, a worker
 * killed — the action is left Running and `studio:dispatch-due` sends it
 * again: failed() only logs, and settles nothing.
 */
final class ExecuteStudioActionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $actionId,
    ) {}

    public function handle(ExecuteStudioAction $execute): void
    {
        $execute->handle($this->actionId);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(DomainLogger::class)->error('studio.action.job_failed', [
            'studio_action_id' => $this->actionId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
