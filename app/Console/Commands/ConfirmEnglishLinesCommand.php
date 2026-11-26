<?php

namespace App\Console\Commands;

use App\Support\Translations\ConfirmedEnglishLines;
use Illuminate\Console\Command;

/**
 * Confirms that every language's interface translation was brought in step
 * with the English it now translates (ConfirmedEnglishLines): run after
 * rewording an English line in lang/en/*.php and updating that line in every
 * other language, and commit the record it writes. A development step, never
 * a deploy one.
 */
class ConfirmEnglishLinesCommand extends Command
{
    protected $signature = 'rateguru:translations:confirm-english';

    protected $description = 'Record the interface English as every language now translates it, after updating the translations of reworded lines';

    public function handle(ConfirmedEnglishLines $lines): int
    {
        foreach ($lines->changed() as $key) {
            $this->line("Confirmed for every language: {$key}");
        }

        $counts = $lines->record();

        $this->info(sprintf(
            'Recorded the English of the interface catalogs in %s: %d reworded, %d new, %d gone.',
            ConfirmedEnglishLines::RECORD,
            $counts['changed'],
            $counts['added'],
            $counts['removed'],
        ));

        return self::SUCCESS;
    }
}
