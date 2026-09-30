<?php

namespace App\Actions\Settings;

use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\DB;
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
        // Which languages the project offers is not a general setting: it is
        // written only by UpdateProjectLocaleSettingsAction, together with the
        // default. Accepting it here would let one payload swap the set out
        // from under the very check below.
        if (array_key_exists('enabled_locales', $settings)) {
            throw new InvalidArgumentException('The offered languages are written by UpdateProjectLocaleSettingsAction, not with the other project settings.');
        }

        $model = DB::transaction(function () use ($settings): ProjectSettings {
            if (array_key_exists('default_locale', $settings)) {
                // Checked against the row as it stands under the lock, so a
                // concurrent change of the offered languages cannot land
                // between the check and the write.
                ProjectSettings::query()->lockForUpdate()->find(1);
                $this->manager->flush();

                if (! $this->locales->isEnabled((string) $settings['default_locale'])) {
                    throw new InvalidArgumentException("The default locale [{$settings['default_locale']}] is not one of the offered languages.");
                }
            }

            return ProjectSettings::updateOrCreate(['id' => 1], $settings);
        });

        $this->manager->flush();

        return $model;
    }
}
