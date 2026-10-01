<?php

namespace App\Support\Translations;

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;
use Closure;
use Illuminate\Support\Str;

/**
 * How completely each language translates this project's own content, read
 * from the database as it is now — admin-created categories and tags
 * included, not just what a preset shipped.
 *
 * Counted: the translatable project settings; every static page of
 * config/static-pages.php; active categories; active rating groups; active,
 * unarchived options of active groups; and every tag. Inactive and archived
 * content is left out, because no visitor sees it. A field whose reference
 * (English) text is blank needs no translation; the reference language itself
 * is complete through its base columns.
 *
 * Only presence is measured — a non-blank string — never quality.
 */
final class ProjectTranslationCompleteness
{
    private const REFERENCE = TranslatableField::REFERENCE_LOCALE;

    public function __construct(private readonly ProjectSettingsManager $settings) {}

    public function report(string $locale): ProjectTranslationReport
    {
        return $this->reports([$locale])[$locale];
    }

    /**
     * Reports for several languages from one read of the content.
     *
     * @param  list<string>  $locales
     * @return array<string, ProjectTranslationReport>
     */
    public function reports(array $locales): array
    {
        $requirements = $this->requirements();
        $reports = [];

        foreach ($locales as $locale) {
            $missing = [];

            foreach ($requirements as [$item, $isTranslated]) {
                if (! $isTranslated($locale)) {
                    $missing[] = $item;
                }
            }

            $reports[$locale] = new ProjectTranslationReport($locale, count($requirements), count($requirements) - count($missing), $missing);
        }

        return $reports;
    }

    /**
     * Every field that needs a translation, with the test of whether a
     * language has one.
     *
     * @return list<array{0: MissingProjectTranslation, 1: Closure(string): bool}>
     */
    private function requirements(): array
    {
        return [
            ...$this->projectSettings(),
            ...$this->staticPages(),
            ...$this->categories(),
            ...$this->ratingGroups(),
            ...$this->ratingOptions(),
            ...$this->tags(),
        ];
    }

    /** @return list<array{0: MissingProjectTranslation, 1: Closure(string): bool}> */
    private function projectSettings(): array
    {
        $row = ProjectSettings::query()->find(1)?->toArray() ?? $this->settings->defaults();
        $requirements = [];

        foreach (PresetSettingsBuilder::TRANSLATABLE as $field) {
            $requirements[] = $this->field(
                new MissingProjectTranslation(ProjectContentSection::ProjectSettings, null, null, $field, Str::headline($field), $field),
                $row[$field] ?? null,
                $row["{$field}_translations"] ?? null,
            );
        }

        return array_values(array_filter($requirements));
    }

    /**
     * A static page's configured text is a full translation as long as the
     * project has not rewritten the page. Once the stored English differs from
     * the configured English, the configured translations describe text that
     * is no longer on the page: every other language then needs its own stored
     * text, and a stored copy of the configured translation — which the Project
     * Settings form writes for every language on save — does not count.
     *
     * @return list<array{0: MissingProjectTranslation, 1: Closure(string): bool}>
     */
    private function staticPages(): array
    {
        $configured = (array) config('static-pages.defaults', []);
        $stored = ProjectSettings::query()->find(1)?->static_pages;
        $stored = is_array($stored) ? $stored : [];
        $requirements = [];

        foreach ($configured as $page => $configuredLocales) {
            foreach (RepositoryTranslations::STATIC_PAGE_FIELDS as $field) {
                $configuredReference = $configuredLocales[self::REFERENCE][$field] ?? null;
                $storedReference = $stored[$page][self::REFERENCE][$field] ?? null;
                $reference = TranslatableField::isPresent($storedReference) ? $storedReference : $configuredReference;

                if (! TranslatableField::isPresent($reference)) {
                    continue;
                }

                $customized = TranslatableField::isPresent($storedReference) && $storedReference !== $configuredReference;

                $requirements[] = [
                    new MissingProjectTranslation(ProjectContentSection::StaticPages, null, null, (string) $page, Str::headline((string) $page), $field),
                    function (string $locale) use ($stored, $configuredLocales, $page, $field, $customized): bool {
                        if ($locale === self::REFERENCE) {
                            return true;
                        }

                        $storedText = $stored[$page][$locale][$field] ?? null;
                        $configuredText = $configuredLocales[$locale][$field] ?? null;

                        if (! $customized) {
                            return TranslatableField::isPresent($storedText) || TranslatableField::isPresent($configuredText);
                        }

                        return TranslatableField::isPresent($storedText)
                            && (! TranslatableField::isPresent($configuredText) || $storedText !== $configuredText);
                    },
                ];
            }
        }

        return $requirements;
    }

    /** @return list<array{0: MissingProjectTranslation, 1: Closure(string): bool}> */
    private function categories(): array
    {
        return Category::query()->active()->orderBy('id')->get()
            ->map(fn (Category $category): ?array => $this->field(
                new MissingProjectTranslation(ProjectContentSection::Categories, $category->id, null, (string) $category->slug, (string) $category->name, 'name'),
                $category->name,
                $category->name_translations,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array{0: MissingProjectTranslation, 1: Closure(string): bool}> */
    private function ratingGroups(): array
    {
        return RatingGroup::query()->active()->orderBy('id')->get()
            ->flatMap(fn (RatingGroup $group): array => [
                $this->field(new MissingProjectTranslation(ProjectContentSection::RatingGroups, $group->id, null, (string) $group->key, (string) $group->label, 'label'), $group->label, $group->label_translations),
                $this->field(new MissingProjectTranslation(ProjectContentSection::RatingGroups, $group->id, null, (string) $group->key, (string) $group->label, 'description'), $group->description, $group->description_translations),
            ])
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array{0: MissingProjectTranslation, 1: Closure(string): bool}> */
    private function ratingOptions(): array
    {
        return RatingOption::query()
            ->active()
            ->whereNull('archived_at')
            ->whereHas('group', fn ($query) => $query->where('is_active', true))
            ->with('group')
            ->orderBy('id')
            ->get()
            ->flatMap(function (RatingOption $option): array {
                $key = "{$option->group->key}.{$option->key}";
                $label = "{$option->group->label} → {$option->label}";

                return [
                    $this->field(new MissingProjectTranslation(ProjectContentSection::RatingOptions, $option->id, $option->rating_group_id, $key, $label, 'label'), $option->label, $option->label_translations),
                    $this->field(new MissingProjectTranslation(ProjectContentSection::RatingOptions, $option->id, $option->rating_group_id, $key, $label, 'description'), $option->description, $option->description_translations),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array{0: MissingProjectTranslation, 1: Closure(string): bool}> */
    private function tags(): array
    {
        return Tag::query()->orderBy('id')->get()
            ->map(fn (Tag $tag): ?array => $this->field(
                new MissingProjectTranslation(ProjectContentSection::Tags, $tag->id, null, (string) $tag->slug, (string) $tag->name, 'name'),
                $tag->name,
                $tag->name_translations,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A translatable model field: required when its reference text is not
     * blank, translated for a language when that language's entry is.
     *
     * @return array{0: MissingProjectTranslation, 1: Closure(string): bool}|null
     */
    private function field(MissingProjectTranslation $item, mixed $reference, mixed $translations): ?array
    {
        if (! TranslatableField::isPresent($reference)) {
            return null;
        }

        $translations = is_array($translations) ? $translations : [];

        return [
            $item,
            fn (string $locale): bool => $locale === self::REFERENCE || TranslatableField::isPresent($translations[$locale] ?? null),
        ];
    }
}
