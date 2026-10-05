<?php

namespace App\Console\Commands;

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Exceptions\Settings\ProjectPresetAlreadyAppliedException;
use App\Exceptions\Settings\ProjectPresetHasContentException;
use App\Exceptions\Settings\UnknownProjectPresetException;
use Illuminate\Console\Command;

class SetupProjectPresetCommand extends Command
{
    protected $signature = 'rateguru:setup
        {preset? : Preset key from config/project_presets.php}
        {--force : Deliberately replace an existing setup or configure a project with content}';

    protected $description = 'Apply the one-time project preset.';

    public function handle(ApplyProjectPresetAction $action): int
    {
        $presets = config('project_presets', []);

        if (! is_array($presets)) {
            $presets = [];
        }

        $presetKey = trim((string) $this->argument('preset'));

        if ($presetKey === '') {
            if (! $this->input->isInteractive()) {
                $this->error('A preset key is required in non-interactive mode.');

                return self::FAILURE;
            }

            $presetKey = (string) $this->choice('Choose a project preset', array_keys($presets));
        }

        if (! array_key_exists($presetKey, $presets)) {
            $this->error(UnknownProjectPresetException::for($presetKey)->getMessage());

            return self::FAILURE;
        }

        $preset = $presets[$presetKey];
        $label = is_array($preset) ? ($preset['label'] ?? $presetKey) : $presetKey;

        $this->info("Selected preset [{$presetKey}]: {$label}");

        if (! $this->option('force')) {
            // Two different things have to be true before a question is worth
            // asking, and isInteractive() is only the first of them.
            //
            // It reports whether --no-interaction was passed; Symfony's
            // configureIO sets it from that flag and from shell verbosity, and
            // never from the stream. So `rateguru:setup nature < /dev/null` — a
            // cron entry, a deploy script, a CI step — arrived here with
            // isInteractive() still true, reached confirm(), got its default of
            // "no", printed "Setup cancelled." and exited 0. Automation could not
            // tell that from an applied preset.
            //
            // The second thing is a terminal to read the answer from.
            if (! $this->canAskForConfirmation()) {
                $this->error('Applying a preset without a terminal requires --force.');

                return self::FAILURE;
            }

            if (! $this->confirm(
                "Apply preset [{$presetKey}]? This replaces project settings, categories, rating configuration, and tags.",
            )) {
                $this->warn('Setup cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $result = $action->handle($presetKey, force: (bool) $this->option('force'));
        } catch (ProjectPresetAlreadyAppliedException|ProjectPresetHasContentException|UnknownProjectPresetException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Changed', 'Count'],
            [
                ['Categories synced', $result->categories],
                ['Categories deactivated', $result->deactivatedCategories],
                ['Rating groups', $result->ratingGroups],
                ['Rating options', $result->ratingOptions],
                ['Tags synced', $result->tags],
                ['Tags removed', $result->removedTags],
            ],
        );
        $this->info("Preset [{$presetKey}] applied successfully.");

        return self::SUCCESS;
    }

    /**
     * Can a question actually be answered on the other end of this run?
     *
     * The same predicate Laravel itself uses to decide whether Prompts may ask
     * anything (Illuminate\Console\Concerns\ConfiguresPrompts::configurePrompts),
     * deliberately reused rather than re-derived: a real terminal, or the test
     * runner, where the question helper is mocked and a question is answerable
     * without one. Diverging from it would mean this command disagreed with every
     * other prompt in the application about what interactive means.
     *
     * The real non-TTY contract is covered by running the CLI as a subprocess with
     * its stdin closed, which is the only way to exercise it honestly.
     */
    private function canAskForConfirmation(): bool
    {
        // --no-interaction is the caller's instruction and outranks everything:
        // it means do not ask, including under the test runner.
        if (! $this->input->isInteractive()) {
            return false;
        }

        // Then something to read the answer from — a terminal, or the test
        // runner, where the question helper is mocked and an answer is available
        // without one. The second half is Laravel's own allowance for tests
        // (ConfiguresPrompts::configurePrompts), reused rather than re-derived, so
        // this command cannot disagree with every other prompt in the application
        // about what interactive means.
        return (defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests();
    }
}
