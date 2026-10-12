<?php

namespace App\Actions\Settings;

use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\TranslatableField;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SaveProjectSettingsAction
{
    public function __construct(
        private readonly ProjectSettingsManager $manager,
    ) {}

    /**
     * The general settings, in English. A static page is given by its English
     * text alone (`static_pages[page][en][title|content]`), which replaces
     * the English of that page and leaves every other language as stored.
     *
     * @param  array<string, mixed>  $settings
     */
    public function handle(array $settings): ProjectSettings
    {
        // Which languages the project offers is not a general setting: it is
        // written only by UpdateProjectLocaleSettingsAction, which keeps the
        // default among them and refuses a language with broken catalogs.
        if (array_key_exists('enabled_locales', $settings)) {
            throw new InvalidArgumentException('The offered languages are written by UpdateProjectLocaleSettingsAction, not with the other project settings.');
        }

        // Translations are Translation Center's, written one at a time by
        // UpdateProjectTranslationAction under its own checks. A copy taken
        // with these settings could only overwrite one saved since.
        foreach (array_keys($settings) as $key) {
            if (str_ends_with((string) $key, '_translations')) {
                throw new InvalidArgumentException('Translations are written by UpdateProjectTranslationAction, not with the other project settings.');
            }
        }

        $pages = $this->englishPages($settings);

        $model = DB::transaction(function () use ($settings, $pages): ProjectSettings {
            // An installation without a row starts from the same bootstrap
            // every other writer uses, not from whatever this payload holds —
            // which is why the row is the manager's to produce, not this
            // action's.
            $row = $this->manager->lockedRow();

            if ($pages !== null) {
                // The other languages as stored now, under this row's lock — never as the caller last saw them.
                $settings['static_pages'] = $this->withEnglish($row->static_pages, $pages);
            }

            $row->fill($settings)->save();

            return $row;
        });

        $this->manager->flush();

        return $model;
    }

    /**
     * The English text of each static page the settings give, or null when
     * they give no static pages; any other language is refused.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, array<string, mixed>>|null
     */
    private function englishPages(array $settings): ?array
    {
        if (! array_key_exists('static_pages', $settings)) {
            return null;
        }

        $pages = is_array($settings['static_pages']) ? $settings['static_pages'] : [];
        $english = [];

        foreach ($pages as $page => $languages) {
            $languages = is_array($languages) ? $languages : [];

            if (array_diff(array_keys($languages), [TranslatableField::REFERENCE_LOCALE]) !== []) {
                throw new InvalidArgumentException('Static pages are given here in English only: their translations are written by UpdateProjectTranslationAction.');
            }

            $english[(string) $page] = is_array($languages[TranslatableField::REFERENCE_LOCALE] ?? null) ? $languages[TranslatableField::REFERENCE_LOCALE] : [];
        }

        return $english;
    }

    /**
     * The stored pages with the English of the given ones replaced.
     *
     * @param  array<string, array<string, mixed>>  $english
     * @return array<string, mixed>
     */
    private function withEnglish(mixed $stored, array $english): array
    {
        $pages = is_array($stored) ? $stored : [];

        foreach ($english as $page => $texts) {
            $languages = is_array($pages[$page] ?? null) ? $pages[$page] : [];
            $languages[TranslatableField::REFERENCE_LOCALE] = $texts;
            $pages[$page] = $languages;
        }

        return $pages;
    }
}
