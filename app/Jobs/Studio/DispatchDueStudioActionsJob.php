<?php

namespace App\Jobs\Studio;

use App\Actions\Studio\DispatchDueStudioActionsAction;
use App\Support\Studio\StudioDispatchWake;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One run of Content Studio's dispatcher (DispatchDueStudioActionsAction) on
 * the queue, the moment something may have become due — rather than at the
 * next minute of `studio:dispatch-due` (StudioDispatchWake). The very pass the
 * minute runs: nothing of its own decides what is due, sends it or takes it
 * back.
 *
 * One waits on the queue at most: another asked for meanwhile is the same run
 * (unique until a worker takes it), and one asked for while it runs waits
 * behind it, so nothing that became due during a run is left for the minute.
 * A run that sent a whole batch may have left more due behind it, and asks for
 * the next one; a run that sent less found everything due there was, so the
 * chain ends. Never retried by the queue: a run lost is the minute's to make up.
 */
final class DispatchDueStudioActionsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** A run lost on its way keeps the next one from the queue no longer than this; the minute covers meanwhile. */
    public int $uniqueFor = 60;

    public function handle(DispatchDueStudioActionsAction $dispatch, StudioDispatchWake $wake): void
    {
        $round = $dispatch->handle();

        if ($round['enabled'] && $round['sent'] >= max(1, (int) config('content_studio.actions.batch_size'))) {
            $wake->now();
        }
    }
}
