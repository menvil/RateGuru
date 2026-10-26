<?php

namespace App\Console\Commands;

use App\Actions\Personas\ImportPersonasAction;
use App\Console\Commands\Concerns\AsksOnATerminal;
use App\Exceptions\Personas\InvalidPersonaFileException;
use App\Models\User;
use App\Support\Personas\PersonaFileReader;
use App\Support\Personas\PersonaImportReport;
use Illuminate\Console\Command;

/**
 * Creates the Personas a JSON file describes — a project's set, kept in the
 * repository (docs/architecture/studio-personas.md, "Importing") — as
 * the administrator named, through ImportPersonasAction: each by the action
 * the persona editor uses, and none that is here already.
 *
 * The whole file is checked first (PersonaFileReader) and what an import
 * would do is said before anything is written: how many are new, how many
 * are here already, and every conflict. A file with any problem writes
 * nothing; so does a conflict, unless --skip-conflicts asks for the rest
 * alone. --dry-run stops after saying so. In production it asks before it
 * writes, unless --force; with nobody to answer, it stops.
 *
 * It is never run by a deploy or a migration: importing is an
 * administrator's decision, made on each environment.
 */
final class ImportStudioPersonasCommand extends Command
{
    use AsksOnATerminal;

    protected $signature = 'studio:personas:import
        {file : The JSON file of Personas, relative to the application\'s root or absolute}
        {--admin= : The id of the administrator who creates them}
        {--dry-run : Check the file and say what an import would do, writing nothing}
        {--skip-conflicts : Import the others when some usernames are another account\'s}
        {--force : Import in production without being asked}';

    protected $description = 'Create the Content Studio Personas a JSON file describes, leaving any already here as they are.';

    public function handle(PersonaFileReader $reader, ImportPersonasAction $import): int
    {
        $admin = $this->administrator();

        if ($admin === null) {
            return self::FAILURE;
        }

        $path = $this->path();

        try {
            $personas = $reader->read($path);
        } catch (InvalidPersonaFileException $exception) {
            $this->error("Nothing was imported: {$exception->getMessage()}");

            foreach ($exception->problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $plan = $import->plan($personas);
        $dryRun = (bool) $this->option('dry-run');

        $this->info("{$path}: {$plan->count()} ".($plan->count() === 1 ? 'persona' : 'personas').', every one valid.');
        $this->table(['To create', 'Here already', 'Conflicts'], [[count($plan->new), count($plan->unchanged), count($plan->conflicts)]]);
        $this->conflicts($plan->conflicts);

        if ($plan->conflicts !== [] && ! $this->option('skip-conflicts')) {
            $this->error(($dryRun ? 'Nothing would be imported' : 'Nothing was imported').': settle the conflicts above, or run again with --skip-conflicts to import the others.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if ($plan->new === []) {
            $this->info($plan->conflicts === []
                ? 'Nothing to import: every persona in the file is here already.'
                : 'Nothing to import: every persona in the file is here already, or in conflict.');

            return self::SUCCESS;
        }

        if (! $this->confirmedInProduction(count($plan->new))) {
            return self::FAILURE;
        }

        $report = $import->handle($admin, $plan);
        $this->report($report);

        return $report->finished() ? self::SUCCESS : self::FAILURE;
    }

    /** The administrator --admin names, when one exists who may manage Personas; otherwise says why not. */
    private function administrator(): ?User
    {
        $id = trim((string) $this->option('admin'));

        if (preg_match('/\A[1-9][0-9]{0,18}\z/', $id) !== 1) {
            $this->error('Name the administrator who creates the personas: --admin=<user id>.');

            return null;
        }

        $admin = User::query()->find((int) $id);

        if ($admin === null) {
            $this->error("There is no user #{$id}.");

            return null;
        }

        if (! $admin->can('manage-personas')) {
            $this->error("User #{$id} may not manage personas: an active administrator is needed.");

            return null;
        }

        return $admin;
    }

    private function path(): string
    {
        $file = (string) $this->argument('file');

        return str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : base_path($file);
    }

    /** In production, whether the administrator at the terminal said to go on. */
    private function confirmedInProduction(int $count): bool
    {
        if (! $this->laravel->environment('production') || $this->option('force')) {
            return true;
        }

        if (! $this->canAskForConfirmation()) {
            $this->error('Importing in production without a terminal requires --force.');

            return false;
        }

        if (! $this->confirm("Create {$count} ".($count === 1 ? 'persona' : 'personas').' in production?')) {
            $this->warn('Import cancelled: nothing was written.');

            return false;
        }

        return true;
    }

    /** @param  array<string, string>  $conflicts */
    private function conflicts(array $conflicts): void
    {
        foreach ($conflicts as $username => $reason) {
            $this->warn("  - {$username}: {$reason}");
        }
    }

    private function report(PersonaImportReport $report): void
    {
        $this->table(
            ['Created', 'Skipped: here already', 'Skipped: conflicts', 'Failed', 'Not attempted'],
            [[count($report->created), count($report->unchanged), count($report->conflicts), count($report->failed), count($report->notAttempted)]],
        );

        foreach ($report->failed as $username => $reason) {
            $this->error("  - {$username}: {$reason}");
        }

        if ($report->notAttempted !== []) {
            $this->warn('Not attempted: '.implode(', ', $report->notAttempted).'. Run the import again once the reason above is settled.');
        }

        if ($report->finished()) {
            $this->info('Import finished.');
        }
    }
}
