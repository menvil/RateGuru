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

        if (! $this->option('force') && ! $this->confirm(
            "Apply preset [{$presetKey}]? This replaces project settings, categories, rating configuration, and tags.",
        )) {
            $this->warn('Setup cancelled.');

            return self::SUCCESS;
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
}
