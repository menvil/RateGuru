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
            $settings = $this->manager->lockedRow();

            $settings->fill(['enabled_locales' => $enabled])->save();

            return $settings;
        });

        $this->manager->flush();

        return $settings;
    }

    /**
     * Offers one more language, or stops offering one, applied to what the
     * project offers once its row is locked — so a change another
     * administrator saved meanwhile is kept, not overwritten by a list
     * worked out before it.
     */
    public function change(string $locale, bool $offered): ProjectSettings
    {
        if (! array_key_exists($locale, $this->locales->supported())) {
            throw new InvalidArgumentException("Locale [{$locale}] is not installed.");
        }

        if (! $offered && $locale === $this->locales->default()) {
            throw new InvalidArgumentException("The default locale [{$locale}] is always offered and cannot be left out.");
        }

        // Read before the lock, as it reads files; whether it decides is
        // known only under the lock, from what the project offers then.
        $catalog = $offered ? $this->catalogs->inspect($locale) : null;

        $settings = DB::transaction(function () use ($locale, $offered, $catalog): ProjectSettings {
            $settings = $this->manager->lockedRow();
            // Read as defensively as LocaleManager reads it: a damaged row
            // offers what it can, and this write repairs it.
            $stored = $settings->getAttribute('enabled_locales');
            $now = array_keys($this->locales->offeredBy($stored === null || is_array($stored) ? $stored : []));

            // A language newly offered needs a whole catalog; one offered
            // already keeps being offered, whatever a release broke.
            if ($catalog !== null && ! in_array($locale, $now, true) && ! $catalog->isComplete()) {
                throw new IncompleteLocaleCatalogException($locale, count($catalog->issues));
            }

            $wanted = $offered ? [...$now, $locale] : array_diff($now, [$locale]);

            // Config order, as handle() stores it.
            $settings->fill(['enabled_locales' => array_values(array_intersect(array_keys($this->locales->supported()), $wanted))])->save();

            return $settings;
        });

        $this->manager->flush();

        return $settings;
    }
}
