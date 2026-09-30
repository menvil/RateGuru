<?php

namespace App\Actions\Settings;

use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use InvalidArgumentException;

final class SaveProjectSettingsAction
{
    public function __construct(
        private readonly ProjectSettingsManager $manager,
        private readonly LocaleManager $locales,
    ) {}

    /** @param array<string, mixed> $settings */
    public function handle(array $settings): ProjectSettings
    {
        // The project default has to be a language the project offers; which
        // languages those are is written by UpdateProjectLocaleSettingsAction.
        if (array_key_exists('default_locale', $settings) && ! $this->locales->isEnabled((string) $settings['default_locale'])) {
            throw new InvalidArgumentException("The default locale [{$settings['default_locale']}] is not one of the offered languages.");
        }

        $model = ProjectSettings::updateOrCreate(['id' => 1], $settings);

        $this->manager->flush();

        return $model;
    }
}
