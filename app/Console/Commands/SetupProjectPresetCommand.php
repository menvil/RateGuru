<?php

namespace App\Console\Commands;

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Exceptions\Settings\ProjectPresetAlreadyAppliedException;
use App\Exceptions\Settings\ProjectPresetHasContentException;
use App\Exceptions\Settings\UnknownProjectPresetException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;

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

                // FAILURE, not SUCCESS, and this is the half of the contract that
                // does not depend on detecting anything.
                //
                // The guard above can still be wrong: runningUnitTests() is keyed off
                // APP_ENV, so a real deploy script running with APP_ENV=testing and no
                // terminal passes it, reaches this question and gets its default of
                // "no". Reporting that as success is what made an unapplied preset
                // indistinguishable from an applied one, and no amount of environment
                // sniffing is a safe thing to rest that on.
                //
                // So the command's exit code says what happened rather than how it
                // was asked: the preset was not applied. A person who answers "no"
                // interactively gets the same answer, which is the honest one.
                return self::FAILURE;
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
     * Laravel answers the same question for Prompts as "a terminal, OR
     * runningUnitTests()" (ConfiguresPrompts::configurePrompts), and this command
     * copied that — which is wrong here. runningUnitTests() reads APP_ENV, and
     * APP_ENV is not evidence about stdin: `APP_ENV=testing php artisan
     * rateguru:setup nature < /dev/null` has no terminal and no mocked question
     * helper, and that spelling passed it.
     *
     * The input's own TYPE is the honest signal. A real command line arrives as
     * ArgvInput and can only be answered by a stream, so it needs a terminal. The
     * test harness invokes commands through Illuminate\Console\Application::call,
     * which builds an ArrayInput, and there the answer comes from the harness
     * rather than from any stream. Nothing here consults the environment.
     *
     * This decides the MESSAGE, not the safety: a declined or unanswerable
     * confirmation returns FAILURE either way, so a guard that guessed wrong could
     * never report an unapplied preset as applied.
     */
    private function canAskForConfirmation(): bool
    {
        // --no-interaction is the caller's instruction and outranks everything:
        // it means do not ask, including under the test harness.
        if (! $this->input->isInteractive()) {
            return false;
        }

        if ($this->input instanceof ArgvInput) {
            return defined('STDIN') && stream_isatty(STDIN);
        }

        return true;
    }
}
