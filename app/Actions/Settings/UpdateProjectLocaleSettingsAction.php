<?php

namespace App\Actions\Settings;

use App\Exceptions\Settings\IncompleteLocaleCatalogException;
use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\TranslationCatalogInspector;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Which installed languages the project offers. The default is not part of
 * it: English is the default by system policy, and always offered.
 *
 * Refuses rather than repairs: a code that is not installed and a set without
 * the default are caller errors. Reading tolerates a bad row
 * (LocaleManager::enabled()); writing never produces one.
 *
 * A language whose application catalogs break the contract is never newly
 * offered, whoever asks (IncompleteLocaleCatalogException). One already
 * offered is left alone, so a broken release does not lock the rest of the
 * project's language settings.
 */
final class UpdateProjectLocaleSettingsAction
{
    public function __construct(
        private readonly LocaleManager $locales,
        private readonly ProjectSettingsManager $manager,
        private readonly TranslationCatalogInspector $catalogs,
    ) {}

    /** @param  list<string>  $enabledLocales */
    public function handle(array $enabledLocales): ProjectSettings
    {
        $installed = array_keys($this->locales->supported());

        foreach ($enabledLocales as $locale) {
            if (! in_array($locale, $installed, true)) {
                throw new InvalidArgumentException("Locale [{$locale}] is not installed.");
            }
        }

        $default = $this->locales->default();

        if (! in_array($default, $enabledLocales, true)) {
            throw new InvalidArgumentException("The default locale [{$default}] is always offered and cannot be left out.");
        }

        // Config order and one entry per language, whatever the caller sent.
        $enabled = array_values(array_intersect($installed, $enabledLocales));

        foreach (array_diff($enabled, $this->locales->enabledCodes()) as $locale) {
            $catalog = $this->catalogs->inspect($locale);

            if (! $catalog->isComplete()) {
                throw new IncompleteLocaleCatalogException($locale, count($catalog->issues));
            }
        }

        $settings = DB::transaction(function () use ($enabled): ProjectSettings {
            $settings = ProjectSettings::query()->lockForUpdate()->find(1);

            if ($settings === null) {
                // PostgreSQL locks no row that does not exist, so two first
                // saves both saw null here and both inserted id 1 — one of them
                // failing on the duplicate key. firstOrCreate settles which one
                // creates it, and the lock below is then taken on a row that is
                // really there.
                ProjectSettings::unguarded(fn () => ProjectSettings::query()->firstOrCreate(
                    ['id' => 1],
                    $this->manager->defaults(),
                ));

                $settings = ProjectSettings::query()->lockForUpdate()->findOrFail(1);
            }

            $settings->fill(['enabled_locales' => $enabled])->save();

            return $settings;
        });

        $this->manager->flush();

        return $settings;
    }
}
