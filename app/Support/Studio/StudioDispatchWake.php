<?php

namespace App\Support\Studio;

use App\Jobs\Studio\DispatchDueStudioActionsJob;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Wakes Content Studio's dispatcher the moment actions may have become due,
 * so a plan runs as fast as its actions allow rather than a minute a
 * generation (docs/architecture/studio-plans.md, "Running a plan"): once an
 * approval is committed, once an action is done with actions waiting for it,
 * once an administrator retries or resumes one, and once the executor is
 * switched on. Each sends one DispatchDueStudioActionsJob — the dispatcher's
 * own pass, with its own locks, attempts and recovery — and only after the
 * write that made something due has committed.
 *
 * Waking is never what makes an action run: `studio:dispatch-due` still runs
 * every minute, sends whatever is due and takes back whatever was lost, and a
 * wake that fails is only logged. Nothing is sent while the executor is
 * switched off.
 */
final class StudioDispatchWake
{
    public function __construct(
        private readonly StudioExecutionSwitch $switch,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * Once the transaction around the caller commits — at once outside one —
     * and never when it is rolled back.
     */
    public function afterCommit(): void
    {
        DB::afterCommit(fn () => $this->now());
    }

    public function now(): void
    {
        try {
            if (! $this->switch->isOn()) {
                return;
            }

            DispatchDueStudioActionsJob::dispatch();
        } catch (Throwable $exception) {
            // The minute's run sends whatever this one would have.
            report($exception);
            $this->logger->warning('studio.dispatch.wake_failed', ['exception' => $exception::class]);
        }
    }
}
