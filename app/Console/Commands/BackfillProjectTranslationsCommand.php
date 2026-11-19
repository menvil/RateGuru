<?php

namespace App\Console\Commands;

use App\Actions\Translations\BackfillProjectTranslationsAction;
use App\Actions\Translations\BaselineProjectTranslationSourcesAction;
use Illuminate\Console\Command;

/**
 * The deploy step that fills in the project translations a release ships —
 * only where the project has none and has not changed the text being
 * translated — and then records the English every stored translation stands
 * beside where none is recorded yet, so outdated translations are told apart
 * from the moment the release serves. Safe to run on every deployment and to
 * run again.
 */
class BackfillProjectTranslationsCommand extends Command
{
    protected $signature = 'rateguru:translations:backfill';

    protected $description = 'Fill missing project translations the repository ships, for content the project has not changed';

    public function handle(BackfillProjectTranslationsAction $action, BaselineProjectTranslationSourcesAction $baseline): int
    {
        $report = $action->handle();

        $this->info(sprintf(
            'Translation backfill: filled %d, already present %d, skipped as customized %d, skipped as unknown %d.',
            $report->filled,
            $report->alreadyPresent,
            $report->skippedCustomized,
            $report->skippedUnknown,
        ));

        // Every translation now stored, the shipped ones included, beside the English it stands with —
        // before the release serves anyone, so an English edit after it is told apart.
        $this->info(sprintf('Recorded the English of %d project translations that had none recorded.', $baseline->handle()));

        return self::SUCCESS;
    }
}
