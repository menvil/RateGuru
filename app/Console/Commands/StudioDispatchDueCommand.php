<?php

namespace App\Console\Commands;

use App\Actions\Studio\DispatchDueStudioActionsAction;
use Illuminate\Console\Command;

/**
 * Content Studio's dispatcher, run by the scheduler every minute: sends the
 * approved plans' actions that are due, sends again those lost on their way
 * or left behind by a worker that died, and gives up on one sent as often as
 * it may be (DispatchDueStudioActionsAction). Does nothing while the
 * executor is switched off.
 */
final class StudioDispatchDueCommand extends Command
{
    protected $signature = 'studio:dispatch-due';

    protected $description = 'Send the Content Studio actions that are due, and send again those lost on their way.';

    public function handle(DispatchDueStudioActionsAction $dispatch): int
    {
        $report = $dispatch->handle();

        if (! $report['enabled']) {
            $this->line('Content Studio execution is switched off: nothing was sent.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Sent %d, sent again %d, given up on %d.',
            $report['sent'],
            $report['resent'],
            $report['exhausted'],
        ));

        return self::SUCCESS;
    }
}
