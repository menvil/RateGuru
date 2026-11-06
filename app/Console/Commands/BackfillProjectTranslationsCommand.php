<?php

namespace App\Console\Commands;

use App\Actions\Translations\BackfillProjectTranslationsAction;
use Illuminate\Console\Command;

/**
 * The deploy step that fills in the project translations a release ships —
 * only where the project has none and has not changed the text being
 * translated. Safe to run on every deployment and to run again.
 */
class BackfillProjectTranslationsCommand extends Command
{
    protected $signature = 'rateguru:translations:backfill';

    protected $description = 'Fill missing project translations the repository ships, for content the project has not changed';

    public function handle(BackfillProjectTranslationsAction $action): int
    {
        $report = $action->handle();

        $this->info(sprintf(
            'Translation backfill: filled %d, already present %d, skipped as customized %d, skipped as unknown %d.',
            $report->filled,
            $report->alreadyPresent,
            $report->skippedCustomized,
            $report->skippedUnknown,
        ));

        return self::SUCCESS;
    }
}
