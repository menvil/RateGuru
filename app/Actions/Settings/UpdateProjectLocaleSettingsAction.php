<?php

namespace App\Actions\Settings;

use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Which installed languages the project offers, and which of them new visitors
 * get — written together, so the two can never disagree.
 *
 * Refuses rather than repairs: a code that is not installed, an empty set and a
 * default outside the set are caller errors. Reading tolerates a bad row
 * (LocaleManager::enabled()); writing never produces one.
 */
final class UpdateProjectLocaleSettingsAction
{
    public function __construct(
        private readonly LocaleManager $locales,
        private readonly ProjectSettingsManager $manager,
    ) {}

    /** @param  list<string>  $enabledLocales */
    public function handle(array $enabledLocales, string $defaultLocale): ProjectSettings
    {
        $installed = array_keys($this->locales->supported());

        foreach ($enabledLocales as $locale) {
            if (! in_array($locale, $installed, true)) {
                throw new InvalidArgumentException("Locale [{$locale}] is not installed.");
            }
        }

        // Config order and one entry per language, whatever the caller sent.
        $enabled = array_values(array_intersect($installed, $enabledLocales));

        if ($enabled === []) {
            throw new InvalidArgumentException('A project must offer at least one language.');
        }

        if (! in_array($defaultLocale, $enabled, true)) {
            throw new InvalidArgumentException("The default locale [{$defaultLocale}] is not one of the offered languages.");
        }

        $settings = DB::transaction(function () use ($enabled, $defaultLocale): ProjectSettings {
            $settings = ProjectSettings::query()->lockForUpdate()->find(1)
                ?? ProjectSettings::unguarded(fn (): ProjectSettings => new ProjectSettings(['id' => 1, ...$this->manager->defaults()]));

            $settings->fill([
                'enabled_locales' => $enabled,
                'default_locale' => $defaultLocale,
            ])->save();

            return $settings;
        });

        $this->manager->flush();

        return $settings;
    }
}
