<?php

namespace App\Support\Translations;

use App\Support\Settings\PresetSettingsBuilder;
use Illuminate\Support\Str;

/**
 * The translatable project content the repository itself ships, read from the
 * two places it already lives — config/project_presets.php and
 * config/static-pages.php — and nowhere else.
 *
 * Each value carries the identity that finds the same content in a project's
 * database, the same one preset application uses: a category by slug, a rating
 * group by key, an option by its group's key and its own, a tag by the slug of
 * its English name, a setting by field, a static page by key.
 */
final class RepositoryTranslations
{
    /** The fields of a static page that are translated. */
    public const STATIC_PAGE_FIELDS = ['title', 'content'];

    /**
     * Every translatable value of every preset, by preset key.
     *
     * @return array<string, list<RepositoryTranslation>>
     */
    public function presets(): array
    {
        $presets = config('project_presets', []);

        return collect(is_array($presets) ? $presets : [])
            ->filter(fn (mixed $preset): bool => is_array($preset))
            ->map(fn (array $preset, string $key): array => $this->fromPreset($key, $preset))
            ->all();
    }

    /** @return list<RepositoryTranslation> */
    public function forPreset(string $key): array
    {
        $preset = config("project_presets.{$key}");

        return is_array($preset) ? $this->fromPreset($key, $preset) : [];
    }

    /** @return list<RepositoryTranslation> */
    public function staticPages(): array
    {
        $entries = [];

        foreach ((array) config('static-pages.defaults', []) as $page => $locales) {
            foreach (self::STATIC_PAGE_FIELDS as $field) {
                $entries[] = new RepositoryTranslation(
                    ProjectContentSection::StaticPages,
                    "static-pages.defaults.{$page}.{$field}",
                    ['page' => (string) $page],
                    $field,
                    collect(is_array($locales) ? $locales : [])->map(fn (mixed $localized): mixed => is_array($localized) ? ($localized[$field] ?? null) : null)->all(),
                );
            }
        }

        return $entries;
    }

    /**
     * Every value the repository ships with a text in a language besides
     * English. It ships project content in English alone — a project
     * translates its own, in Translation Center — so a release must not merge
     * with any.
     *
     * @return list<string>
     */
    public function translated(): array
    {
        $problems = [];
        $entries = [...array_merge(...array_values($this->presets())), ...$this->staticPages()];

        foreach ($entries as $entry) {
            foreach (array_keys($entry->values) as $locale) {
                if ($locale !== TranslatableField::REFERENCE_LOCALE && $entry->value((string) $locale) !== null) {
                    $problems[] = "{$entry->source} has [{$locale}] text";
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return list<RepositoryTranslation>
     */
    private function fromPreset(string $key, array $preset): array
    {
        $entries = [];
        $settings = is_array($preset['settings'] ?? null) ? $preset['settings'] : [];

        foreach (PresetSettingsBuilder::TRANSLATABLE as $field) {
            if (array_key_exists($field, $settings)) {
                $entries[] = $this->entry(ProjectContentSection::ProjectSettings, "project_presets.{$key}.settings.{$field}", ['field' => $field], $field, $settings[$field]);
            }
        }

        foreach ((array) ($preset['categories'] ?? []) as $category) {
            $slug = (string) ($category['slug'] ?? '');
            $entries[] = $this->entry(ProjectContentSection::Categories, "project_presets.{$key}.categories[{$slug}].name", ['slug' => $slug], 'name', $category['name'] ?? null);
        }

        foreach ((array) ($preset['rating_groups'] ?? []) as $group) {
            $groupKey = (string) ($group['key'] ?? '');

            foreach (['label', 'description'] as $field) {
                $entries[] = $this->entry(ProjectContentSection::RatingGroups, "project_presets.{$key}.rating_groups[{$groupKey}].{$field}", ['key' => $groupKey], $field, $group[$field] ?? null);
            }

            foreach ((array) ($group['options'] ?? []) as $option) {
                $optionKey = (string) ($option['key'] ?? '');

                foreach (['label', 'description'] as $field) {
                    $entries[] = $this->entry(ProjectContentSection::RatingOptions, "project_presets.{$key}.rating_groups[{$groupKey}].options[{$optionKey}].{$field}", ['group' => $groupKey, 'option' => $optionKey], $field, $option[$field] ?? null);
                }
            }
        }

        foreach ((array) ($preset['tags'] ?? []) as $tag) {
            // The identity preset application gives a tag: the slug of its English name.
            $values = $this->values($tag);
            $slug = Str::slug((string) ($values[TranslatableField::REFERENCE_LOCALE] ?? ''));
            $entries[] = new RepositoryTranslation(ProjectContentSection::Tags, "project_presets.{$key}.tags[{$slug}].name", ['slug' => $slug], 'name', $values);
        }

        return $entries;
    }

    /** @param  array<string, string>  $identity */
    private function entry(ProjectContentSection $section, string $source, array $identity, string $field, mixed $value): RepositoryTranslation
    {
        return new RepositoryTranslation($section, $source, $identity, $field, $this->values($value));
    }

    /**
     * A preset value as values per language. A plain string is the reference
     * text alone — the documented shape of a custom preset that translates
     * nothing.
     *
     * @return array<string, mixed>
     */
    private function values(mixed $value): array
    {
        return match (true) {
            is_array($value) => $value,
            is_string($value) => [TranslatableField::REFERENCE_LOCALE => $value],
            default => [],
        };
    }
}
