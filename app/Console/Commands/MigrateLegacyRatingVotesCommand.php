<?php

namespace App\Console\Commands;

use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Services\Rating\LegacyRatingVoteMigrator;
use Illuminate\Console\Command;

final class MigrateLegacyRatingVotesCommand extends Command
{
    protected $signature = 'rateguru:rating:migrate-legacy-votes {--dry-run}';

    protected $description = 'Migrate legacy origin and cuisine votes into generic rating votes.';

    public function handle(LegacyRatingVoteMigrator $migrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        try {
            $result = $migrator->migrate($dryRun);
        } catch (InvalidRatingGroupConfigurationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line($dryRun ? 'Dry run: no changes were committed.' : 'Legacy rating votes migrated.');
        $this->table(
            ['Origin', 'Category', 'Existing', 'Unmapped'],
            [[
                $result['origin_migrated'],
                $result['category_migrated'],
                $result['existing'],
                $result['unmapped'],
            ]],
        );

        return self::SUCCESS;
    }
}
