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
 * Counted: the translatable project settings; every built-in static page;
 * active categories; active rating groups; active,
 * unarchived options of active groups; and every tag. Inactive and archived
 * content is left out, because no visitor sees it. A field whose reference
 * (English) text is blank needs no translation; the reference language itself
 * is complete through its base columns.
 *
 * Everything is read from the database as it is now, static pages included:
 * repository config is never consulted for what a project shows. Only
 * presence is measured — a non-blank string — never quality.
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
     * The static pages as the project stores them — the only text a visitor
     * is shown. Which pages there are is the application's (config keys); what
     * they say is the database's, and a language without its own text for a
     * field is missing it, whatever the repository once shipped.
     *
     * @return list<array{0: MissingProjectTranslation, 1: Closure(string): bool}>
     */
    private function staticPages(): array
    {
        $row = ProjectSettings::query()->find(1);
        $stored = $row !== null ? $row->static_pages : $this->settings->defaults()['static_pages'];
        $stored = is_array($stored) ? $stored : [];
        $requirements = [];

        foreach (array_keys((array) config('static-pages.defaults', [])) as $page) {
            $localized = is_array($stored[$page] ?? null) ? $stored[$page] : [];

            foreach (RepositoryTranslations::STATIC_PAGE_FIELDS as $field) {
                $requirements[] = $this->field(
                    new MissingProjectTranslation(ProjectContentSection::StaticPages, null, null, (string) $page, Str::headline((string) $page), $field),
                    is_array($localized[self::REFERENCE] ?? null) ? ($localized[self::REFERENCE][$field] ?? null) : null,
                    collect($localized)->map(fn (mixed $texts): mixed => is_array($texts) ? ($texts[$field] ?? null) : null)->all(),
                );
            }
        }

        return array_values(array_filter($requirements));
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
     * blank, translated for a language when that language's entry is. The
     * item carries that reference text, so whoever lists it can show what
     * the translation is made from.
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
            $item->withReference((string) $reference),
            fn (string $locale): bool => $locale === self::REFERENCE || TranslatableField::isPresent($translations[$locale] ?? null),
        ];
    }
}
