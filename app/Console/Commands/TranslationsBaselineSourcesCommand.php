<?php

namespace App\Console\Commands;

use App\Actions\Translations\BaselineProjectTranslationSourcesAction;
use Illuminate\Console\Command;

/**
 * Records, for every stored project translation that has nothing recorded,
 * the English it stands beside now — so a later change of that English shows
 * the translation as outdated in Translation Center. Only adds: a record
 * that exists is never replaced. Safe to run again, and scheduled daily for
 * translations written other than through Translation Center.
 */
final class TranslationsBaselineSourcesCommand extends Command
{
    protected $signature = 'translations:baseline-sources';

    protected $description = 'Record the English each stored project translation was made from, where nothing is recorded yet.';

    public function handle(BaselineProjectTranslationSourcesAction $action): int
    {
        $added = $action->handle();

        $this->info("Recorded the English of {$added} project translations that had none recorded.");

        return self::SUCCESS;
    }
}
