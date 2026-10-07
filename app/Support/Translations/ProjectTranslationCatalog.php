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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one list of this project's translatable content, and where each piece
 * of it lives.
 *
 * Listed: the translatable project settings; every built-in static page;
 * active categories; active rating groups; active, unarchived options of
 * active groups; and every tag — what a visitor can see. Inactive and archived
 * content is left out until it is shown again; its own editor keeps its
 * translations meanwhile.
 *
 * Everything is read from the database as it is now, static pages included:
 * repository config only names which pages are built in, and stands in for a
 * settings row an installation has not created yet — the bootstrap it would be
 * created from. It is never a fallback for a translation.
 *
 * Languages counts what is missing from these units, and Translation Center
 * edits them, so the two can never disagree about what is translatable. Each
 * unit is written back where it was read from — a `{field}_translations`
 * column, or a language's entry in the static pages — one language at a time,
 * under a lock on its row.
 */
final class ProjectTranslationCatalog
{
    private const REFERENCE = TranslatableField::REFERENCE_LOCALE;

    /**
     * Every translated field of every section with the limits its editor
     * enforces: maximum length in characters, and whether it takes more than
     * one line. The editors that still translate in place hold the same
     * limits; a test keeps them equal.
     *
     * @var array<string, array<string, array{0: int, 1: bool}>>
     */
    public const FIELDS = [
        'project_settings' => [
            'site_name' => [120, false],
            'site_tagline' => [180, false],
            'site_description' => [2000, true],
            'object_singular_name' => [80, false],
            'object_plural_name' => [80, false],
            'upload_cta_label' => [80, false],
            'feed_title' => [120, false],
        ],
        'static_pages' => [
            'title' => [160, false],
            'content' => [20000, true],
        ],
        'categories' => [
            'name' => [80, false],
        ],
        'rating_groups' => [
            'label' => [120, false],
            'description' => [1000, true],
        ],
        'rating_options' => [
            'label' => [120, false],
            'description' => [1000, true],
        ],
        'tags' => [
            'name' => [80, false],
        ],
    ];

    /** Where a visitor meets each project setting, for whoever translates it. */
    private const SETTING_USAGE = [
        'site_name' => 'The project’s name: in the site header, the browser tab, shared links and the static pages.',
        'site_tagline' => 'The project’s tagline: one short line saying what the site is about.',
        'site_description' => 'A plain description of the project, a sentence or two long.',
        'object_singular_name' => 'What one item on the site is called, as in “1 post”.',
        'object_plural_name' => 'What several items on the site are called, as in “12 posts”.',
        'upload_cta_label' => 'The upload button in the site header, and the title of the upload dialog.',
        'feed_title' => 'The heading above the main feed of the newest items.',
    ];

    public function __construct(private readonly ProjectSettingsManager $settings) {}

    /**
     * Every unit, section by section in the sections' own order, from one read
     * per section: the settings row once, then one query for categories, one
     * for rating groups, one for options with their groups, and one for tags.
     *
     * @return list<ProjectTranslationUnit>
     */
    public function units(): array
    {
        $row = ProjectSettings::query()->find(1);

        return [
            ...$this->settingsUnits($row),
            ...$this->categories()->orderBy('id')->get()->flatMap(fn (Category $category): array => $this->categoryUnits($category))->all(),
            ...$this->ratingGroups()->orderBy('id')->get()->flatMap(fn (RatingGroup $group): array => $this->ratingGroupUnits($group))->all(),
            ...$this->ratingOptions()->orderBy('id')->get()->flatMap(fn (RatingOption $option): array => $this->ratingOptionUnits($option))->all(),
            ...$this->tags()->orderBy('id')->get()->flatMap(fn (Tag $tag): array => $this->tagUnits($tag))->all(),
        ];
    }

    /**
     * One unit, read on its own — only the row it lives in — or null when the
     * id names nothing the catalog lists now: a malformed id, a deleted
     * record, content no visitor sees any more.
     */
    public function find(mixed $id): ?ProjectTranslationUnit
    {
        $address = $this->address($id);

        if ($address === null) {
            return null;
        }

        $row = match ($address['section']) {
            ProjectContentSection::ProjectSettings, ProjectContentSection::StaticPages => ProjectSettings::query()->find(1),
            default => $this->records($address['section'])->find($address['record']),
        };

        return $this->unitIn($address['section'], $row, (string) $id);
    }

    /**
     * Several units, read together and as they are now: by id, for the ids
     * that name something the catalog lists; any other id is simply absent.
     * The same units find() returns one at a time, from at most one read of
     * the settings row and one query per model section, however many ids.
     *
     * @param  array<mixed>  $ids
     * @return array<string, ProjectTranslationUnit>
     */
    public function findMany(array $ids): array
    {
        $addresses = [];

        foreach ($ids as $id) {
            $address = $this->address($id);

            if ($address !== null) {
                $addresses[(string) $id] = $address;
            }
        }

        $settingsRow = null;
        $records = [];
        $recordIds = [];

        foreach ($addresses as $address) {
            if (! in_array($address['section'], [ProjectContentSection::ProjectSettings, ProjectContentSection::StaticPages], true)) {
                $recordIds[$address['section']->value][] = $address['record'];
            }
        }

        if (array_filter($addresses, fn (array $address): bool => $address['record'] === null) !== []) {
            $settingsRow = ProjectSettings::query()->find(1);
        }

        foreach ($recordIds as $section => $ids) {
            $records[$section] = $this->records(ProjectContentSection::from($section))
                ->whereIn('id', array_values(array_unique($ids)))
                ->get()
                ->keyBy('id')
                ->all();
        }

        $units = [];

        foreach ($addresses as $id => $address) {
            $row = $address['record'] === null ? $settingsRow : ($records[$address['section']->value][$address['record']] ?? null);
            $unit = $this->unitIn($address['section'], $row, $id);

            if ($unit !== null) {
                $units[$id] = $unit;
            }
        }

        return $units;
    }

    /**
     * Writes one language's text of one unit where the unit lives, touching
     * that language's entry and nothing else: the row is locked and read
     * again, so a translation saved meanwhile — in another language, or by
     * the editor that still translates in place — is kept. Blank text removes
     * the language's entry, so the language is missing again; a translation
     * map left empty is stored as null.
     *
     * $accept sees the unit as the locked row holds it, before anything is
     * written, and throws to refuse the write.
     *
     * MUST be called inside the caller's transaction: the lock is held until
     * it ends.
     *
     * @param  Closure(ProjectTranslationUnit): void  $accept
     * @return ProjectTranslationUnit|null the unit as it is stored now, or null when the id names nothing the catalog lists now
     */
    public function write(mixed $id, string $locale, string $text, Closure $accept): ?ProjectTranslationUnit
    {
        if ($locale === self::REFERENCE) {
            throw new InvalidArgumentException('The reference text is written by the content’s own editor, not as a translation.');
        }

        $address = $this->address($id);

        if ($address === null) {
            return null;
        }

        $section = $address['section'];
        $row = match ($section) {
            ProjectContentSection::ProjectSettings, ProjectContentSection::StaticPages => $this->settings->lockedRow(),
            default => $this->records($section)->lockForUpdate()->find($address['record']),
        };
        $unit = $this->unitIn($section, $row, (string) $id);

        if ($row === null || $unit === null) {
            return null;
        }

        $accept($unit);

        if ($section === ProjectContentSection::StaticPages) {
            $row->setAttribute('static_pages', $this->withPageText($row->getAttribute('static_pages'), (string) $address['page'], $address['field'], $locale, $text));
        } else {
            $column = "{$address['field']}_translations";
            $row->setAttribute($column, $this->withText($row->getAttribute($column), $locale, $text));
        }

        $row->save();

        return $unit->withTranslation($locale, $text);
    }

    /**
     * What an id names, or null for anything that is not one: a section the
     * catalog has, a field of that section, and the record or page — a
     * positive id written the way the catalog writes it, a built-in page.
     *
     * @return array{section: ProjectContentSection, record: ?int, page: ?string, field: string}|null
     */
    private function address(mixed $id): ?array
    {
        if (! is_string($id)) {
            return null;
        }

        $parts = explode(':', $id);
        $section = ProjectContentSection::tryFrom($parts[0]);
        $field = (string) end($parts);

        if ($section === null || ! array_key_exists($field, self::FIELDS[$section->value])) {
            return null;
        }

        return match ($section) {
            ProjectContentSection::ProjectSettings => count($parts) === 2
                ? ['section' => $section, 'record' => null, 'page' => null, 'field' => $field]
                : null,
            ProjectContentSection::StaticPages => count($parts) === 3 && in_array($parts[1], $this->pages(), true)
                ? ['section' => $section, 'record' => null, 'page' => $parts[1], 'field' => $field]
                : null,
            default => count($parts) === 3 && preg_match('/^[1-9][0-9]{0,17}$/', $parts[1]) === 1
                ? ['section' => $section, 'record' => (int) $parts[1], 'page' => null, 'field' => $field]
                : null,
        };
    }

    /** The unit with this id among the units of one row, or null. */
    private function unitIn(ProjectContentSection $section, ?Model $row, string $id): ?ProjectTranslationUnit
    {
        $units = match (true) {
            $section === ProjectContentSection::ProjectSettings => $this->projectSettingUnits($this->settingsAttributes($row)),
            $section === ProjectContentSection::StaticPages => $this->staticPageUnits($row instanceof ProjectSettings ? $row->static_pages : $this->settings->defaults()['static_pages']),
            $row instanceof Category => $this->categoryUnits($row),
            $row instanceof RatingGroup => $this->ratingGroupUnits($row),
            $row instanceof RatingOption => $this->ratingOptionUnits($row),
            $row instanceof Tag => $this->tagUnits($row),
            default => [],
        };

        foreach ($units as $unit) {
            if ($unit->id === $id) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * The records of a model section that the catalog lists.
     *
     * @return Builder<Category>|Builder<RatingGroup>|Builder<RatingOption>|Builder<Tag>
     */
    private function records(ProjectContentSection $section): Builder
    {
        return match ($section) {
            ProjectContentSection::Categories => $this->categories(),
            ProjectContentSection::RatingGroups => $this->ratingGroups(),
            ProjectContentSection::RatingOptions => $this->ratingOptions(),
            ProjectContentSection::Tags => $this->tags(),
            default => throw new InvalidArgumentException("[{$section->value}] is not stored on records of its own."),
        };
    }

    /** @return Builder<Category> */
    private function categories(): Builder
    {
        return Category::query()->active();
    }

    /** @return Builder<RatingGroup> */
    private function ratingGroups(): Builder
    {
        return RatingGroup::query()->active();
    }

    /** @return Builder<RatingOption> */
    private function ratingOptions(): Builder
    {
        return RatingOption::query()
            ->active()
            ->whereNull('archived_at')
            ->whereHas('group', fn (Builder $group) => $group->where('is_active', true))
            ->with('group');
    }

    /** @return Builder<Tag> */
    private function tags(): Builder
    {
        return Tag::query();
    }

    /**
     * The settings row's translatable settings and its static pages; without
     * a row, the bootstrap it would be created from.
     *
     * @return list<ProjectTranslationUnit>
     */
    private function settingsUnits(?ProjectSettings $row): array
    {
        return [
            ...$this->projectSettingUnits($this->settingsAttributes($row)),
            ...$this->staticPageUnits($row !== null ? $row->static_pages : $this->settings->defaults()['static_pages']),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsAttributes(?Model $row): array
    {
        return $row instanceof ProjectSettings ? $row->toArray() : $this->settings->defaults();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<ProjectTranslationUnit>
     */
    private function projectSettingUnits(array $settings): array
    {
        return array_map(fn (string $field): ProjectTranslationUnit => $this->unit(
            ProjectContentSection::ProjectSettings,
            "project_settings:{$field}",
            null,
            null,
            $field,
            Str::headline($field),
            $field,
            $settings[$field] ?? null,
            $settings["{$field}_translations"] ?? null,
            self::SETTING_USAGE[$field],
        ), PresetSettingsBuilder::TRANSLATABLE);
    }

    /**
     * The built-in pages as the project stores them. Which pages there are is
     * the application's (config keys); what they say is the database's.
     *
     * @return list<ProjectTranslationUnit>
     */
    private function staticPageUnits(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $units = [];

        foreach ($this->pages() as $page) {
            $localized = is_array($stored[$page] ?? null) ? $stored[$page] : [];
            $name = Str::headline($page);

            foreach (array_keys(self::FIELDS['static_pages']) as $field) {
                $units[] = $this->unit(
                    ProjectContentSection::StaticPages,
                    "static_pages:{$page}:{$field}",
                    null,
                    null,
                    $page,
                    $name,
                    $field,
                    is_array($localized[self::REFERENCE] ?? null) ? ($localized[self::REFERENCE][$field] ?? null) : null,
                    array_map(fn (mixed $texts): mixed => is_array($texts) ? ($texts[$field] ?? null) : null, $localized),
                    $field === 'title' ? "The title of the {$name} page." : "The text of the {$name} page.",
                );
            }
        }

        return $units;
    }

    /** @return list<ProjectTranslationUnit> */
    private function categoryUnits(Category $category): array
    {
        return [
            $this->unit(ProjectContentSection::Categories, "categories:{$category->id}:name", $category->id, null, (string) $category->slug, (string) $category->name, 'name', $category->name, $category->name_translations, 'The category’s name on posts and in the upload form.'),
        ];
    }

    /** @return list<ProjectTranslationUnit> */
    private function ratingGroupUnits(RatingGroup $group): array
    {
        return [
            $this->unit(ProjectContentSection::RatingGroups, "rating_groups:{$group->id}:label", $group->id, null, (string) $group->key, (string) $group->label, 'label', $group->label, $group->label_translations, 'The title of the rating group above its options on a post, and in the upload form.'),
            $this->unit(ProjectContentSection::RatingGroups, "rating_groups:{$group->id}:description", $group->id, null, (string) $group->key, (string) $group->label, 'description', $group->description, $group->description_translations, 'Stored with the rating group: what it asks voters about.'),
        ];
    }

    /** @return list<ProjectTranslationUnit> */
    private function ratingOptionUnits(RatingOption $option): array
    {
        $key = "{$option->group->key}.{$option->key}";
        $label = "{$option->group->label} → {$option->label}";

        return [
            $this->unit(ProjectContentSection::RatingOptions, "rating_options:{$option->id}:label", $option->id, $option->rating_group_id, $key, $label, 'label', $option->label, $option->label_translations, "A vote button in the {$option->group->label} rating group on a post."),
            $this->unit(ProjectContentSection::RatingOptions, "rating_options:{$option->id}:description", $option->id, $option->rating_group_id, $key, $label, 'description', $option->description, $option->description_translations, 'Stored with the rating option: what a vote for it means.'),
        ];
    }

    /** @return list<ProjectTranslationUnit> */
    private function tagUnits(Tag $tag): array
    {
        return [
            $this->unit(ProjectContentSection::Tags, "tags:{$tag->id}:name", $tag->id, null, (string) $tag->slug, (string) $tag->name, 'name', $tag->name, $tag->name_translations, 'The tag’s name on posts and in the feed’s tag tabs.'),
        ];
    }

    private function unit(
        ProjectContentSection $section,
        string $id,
        ?int $recordId,
        ?int $parentId,
        string $key,
        string $label,
        string $field,
        mixed $reference,
        mixed $translations,
        string $usage,
    ): ProjectTranslationUnit {
        [$maxLength, $multiline] = self::FIELDS[$section->value][$field];

        return new ProjectTranslationUnit(
            $id,
            $section,
            $recordId,
            $parentId,
            $key,
            $label,
            $field,
            is_string($reference) ? $reference : '',
            is_array($translations) ? $translations : [],
            $maxLength,
            $multiline,
            $usage,
        );
    }

    /** @return list<string> the built-in static pages, in config order */
    private function pages(): array
    {
        return array_map(strval(...), array_keys((array) config('static-pages.defaults', [])));
    }

    /**
     * A `{field}_translations` value with one language's text replaced, as
     * read from the locked row — so every other language stays as it is.
     *
     * @return array<string, mixed>|null
     */
    private function withText(mixed $translations, string $locale, string $text): ?array
    {
        $translations = is_array($translations) ? $translations : [];

        if (trim($text) !== '') {
            $translations[$locale] = $text;
        } else {
            unset($translations[$locale]);
        }

        return $translations === [] ? null : $translations;
    }

    /**
     * The static pages with one field of one language of one page replaced.
     * A language left with no field is removed from the page; the other
     * languages, English included, and every other page stay as they are.
     *
     * @return array<string, mixed>
     */
    private function withPageText(mixed $pages, string $page, string $field, string $locale, string $text): array
    {
        $pages = is_array($pages) ? $pages : [];
        $localized = is_array($pages[$page] ?? null) ? $pages[$page] : [];
        $texts = is_array($localized[$locale] ?? null) ? $localized[$locale] : [];

        if (trim($text) !== '') {
            $texts[$field] = $text;
        } else {
            unset($texts[$field]);
        }

        if ($texts === []) {
            unset($localized[$locale]);
        } else {
            $localized[$locale] = $texts;
        }

        $pages[$page] = $localized;

        return $pages;
    }
}
