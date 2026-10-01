<?php

namespace App\Actions\Translations;

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\RepositoryTranslation;
use App\Support\Translations\RepositoryTranslations;
use App\Support\Translations\TranslatableField;
use App\Support\Translations\TranslationBackfillReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the translations the repository ships but the database does not
 * have yet — for every installed language, enabled or not, so a language a
 * release adds is filled in before anyone offers it.
 *
 * The database owns project content; the repository only lends what is
 * missing. A translation is written only when all of these hold:
 *
 *  - the content already exists, found by the identity preset application
 *    uses — no category, tag, group or option is ever created;
 *  - that language has no text for the field — nothing is ever overwritten,
 *    so a translation the repository changes later does not reach a project
 *    that already has one;
 *  - the repository has text for that language;
 *  - the field's reference (English) text is exactly the repository's, so the
 *    translation is of the text the project actually shows. Once a project
 *    changes the text, the repository's translation of the old one is left
 *    out, and the field is for an administrator to translate.
 *
 * Static pages follow the same rules, with one addition: a built-in page, or
 * a field of one, that has no English in the database at all — a page a
 * release adds, or a row saved before pages had content — gets the
 * repository's English, and with it the translations. That fills a gap and
 * replaces nothing.
 *
 * Content the repository does not know — created by an administrator — is
 * the project's alone: every language it lacks is counted as skipped as
 * unknown, never filled.
 *
 * The check is per field, under a lock on the row it writes, so an
 * administrator's edit cannot land between the comparison and the write.
 * Running it again finds nothing more to do, which is what makes it safe on
 * every deploy.
 *
 * Project settings and categories, rating groups, options and tags come from
 * the preset the project was set up with (active_preset_key); static pages
 * from config/static-pages.php.
 */
final class BackfillProjectTranslationsAction
{
    private const REFERENCE = TranslatableField::REFERENCE_LOCALE;

    /** @var array{filled: int, already_present: int, skipped_customized: int, skipped_unknown: int} */
    private array $counts;

    public function __construct(
        private readonly RepositoryTranslations $repository,
        private readonly LocaleManager $locales,
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function handle(): TranslationBackfillReport
    {
        $this->counts = ['filled' => 0, 'already_present' => 0, 'skipped_customized' => 0, 'skipped_unknown' => 0];
        $locales = array_values(array_diff(array_keys($this->locales->supported()), [self::REFERENCE]));

        $presetKey = ProjectSettings::query()->value('active_preset_key');
        $preset = is_string($presetKey) ? $this->repository->forPreset($presetKey) : [];
        $bySection = collect($preset)->groupBy(fn (RepositoryTranslation $entry): string => $entry->section->value);

        $this->projectSettings($bySection->get(ProjectContentSection::ProjectSettings->value, collect())->all(), $locales);

        foreach ($bySection->get(ProjectContentSection::Categories->value, collect()) as $entry) {
            $this->model($entry, $locales, fn (): Builder => Category::query()->where('slug', $entry->identity['slug']));
        }

        foreach ($bySection->get(ProjectContentSection::RatingGroups->value, collect()) as $entry) {
            $this->model($entry, $locales, fn (): Builder => RatingGroup::query()->where('key', $entry->identity['key']));
        }

        foreach ($bySection->get(ProjectContentSection::RatingOptions->value, collect()) as $entry) {
            // An option key is unique within its group only.
            $this->model($entry, $locales, fn (): Builder => RatingOption::query()
                ->where('key', $entry->identity['option'])
                ->whereHas('group', fn (Builder $group) => $group->where('key', $entry->identity['group'])));
        }

        foreach ($bySection->get(ProjectContentSection::Tags->value, collect()) as $entry) {
            $this->model($entry, $locales, fn (): Builder => Tag::query()->where('slug', $entry->identity['slug']));
        }

        $known = fn (ProjectContentSection $section, string $part): array => $bySection->get($section->value, collect())
            ->map(fn (RepositoryTranslation $entry): string => $entry->identity[$part])
            ->unique()
            ->values()
            ->all();
        $knownOptions = $bySection->get(ProjectContentSection::RatingOptions->value, collect())
            ->map(fn (RepositoryTranslation $entry): string => "{$entry->identity['group']}.{$entry->identity['option']}")
            ->all();

        $this->unknownContent(Category::query()->whereNotIn('slug', $known(ProjectContentSection::Categories, 'slug'))->get(), ['name'], $locales);
        $this->unknownContent(Tag::query()->whereNotIn('slug', $known(ProjectContentSection::Tags, 'slug'))->get(), ['name'], $locales);
        $this->unknownContent(RatingGroup::query()->whereNotIn('key', $known(ProjectContentSection::RatingGroups, 'key'))->get(), ['label', 'description'], $locales);
        $this->unknownContent(
            RatingOption::query()->with('group')->get()
                ->reject(fn (RatingOption $option): bool => in_array("{$option->group?->key}.{$option->key}", $knownOptions, true)),
            ['label', 'description'],
            $locales,
        );

        $this->settings->flush();

        return new TranslationBackfillReport(
            $this->counts['filled'],
            $this->counts['already_present'],
            $this->counts['skipped_customized'],
            $this->counts['skipped_unknown'],
        );
    }

    /**
     * The project settings row, which carries both the translatable settings
     * and the static pages — written once, under one lock.
     *
     * @param  list<RepositoryTranslation>  $settingsEntries
     * @param  list<string>  $locales
     */
    private function projectSettings(array $settingsEntries, array $locales): void
    {
        DB::transaction(function () use ($settingsEntries, $locales): void {
            $row = ProjectSettings::query()->lockForUpdate()->find(1);

            if ($row === null) {
                $this->tally('skipped_unknown', (count($settingsEntries) + count($this->repository->staticPages())) * count($locales));

                return;
            }

            $changes = [];

            foreach ($settingsEntries as $entry) {
                $column = "{$entry->field}_translations";
                $translations = $changes[$column] ?? $row->getAttribute($column);
                $filled = $this->fill($entry, $row->getAttribute($entry->field), $translations, $locales);

                if ($filled !== null) {
                    $changes[$column] = $filled;
                }
            }

            $pages = $this->staticPages($row->static_pages, $locales);

            if ($pages !== null) {
                $changes['static_pages'] = $pages;
            }

            if ($changes !== []) {
                $row->forceFill($changes)->save();
            }
        });
    }

    /**
     * The static pages the project stores, with what the repository ships
     * filled in where the project has nothing. A page or field without any
     * English gets the repository's English first; then every language is
     * filled under the usual rules, against that English.
     *
     * @param  list<string>  $locales
     * @return array<string, mixed>|null the pages to store, or null when nothing changed
     */
    private function staticPages(mixed $stored, array $locales): ?array
    {
        if ($stored !== null && ! is_array($stored)) {
            $this->tally('skipped_unknown', count($this->repository->staticPages()) * count($locales));

            return null;
        }

        $pages = $stored ?? [];
        $changed = false;

        foreach ($this->repository->staticPages() as $entry) {
            $page = $entry->identity['page'];
            $field = $entry->field;
            $reference = $entry->reference();

            if ($reference === null) {
                continue;
            }

            if (array_key_exists($page, $pages) && ! is_array($pages[$page])) {
                // Not a page at all: nothing here can be added safely.
                $this->tally('skipped_unknown', count($locales));

                continue;
            }

            $writable = fn (string $locale): bool => ! array_key_exists($locale, $pages[$page] ?? []) || is_array($pages[$page][$locale]);

            if (! TranslatableField::isPresent($pages[$page][self::REFERENCE][$field] ?? null)) {
                if (! $writable(self::REFERENCE)) {
                    $this->tally('skipped_unknown', count($locales));

                    continue;
                }

                // No English at all: the page is not the project's text yet.
                $pages[$page][self::REFERENCE][$field] = $reference;
                $this->tally('filled');
                $changed = true;
            }

            $storedReference = $pages[$page][self::REFERENCE][$field];

            foreach ($locales as $locale) {
                if (TranslatableField::isPresent($pages[$page][$locale][$field] ?? null)) {
                    $this->tally('already_present');
                } elseif ($entry->value($locale) === null || ! $writable($locale)) {
                    $this->tally('skipped_unknown');
                } elseif ($storedReference !== $reference) {
                    $this->tally('skipped_customized');
                } else {
                    $pages[$page][$locale][$field] = $entry->value($locale);
                    $this->tally('filled');
                    $changed = true;
                }
            }
        }

        return $changed ? $pages : null;
    }

    /**
     * Content the repository has nothing for: each language it lacks is
     * counted, and nothing is written.
     *
     * @param  iterable<Model>  $rows
     * @param  list<string>  $fields
     * @param  list<string>  $locales
     */
    private function unknownContent(iterable $rows, array $fields, array $locales): void
    {
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                if (! TranslatableField::isPresent($row->getAttribute($field))) {
                    continue;
                }

                $translations = $row->getAttribute("{$field}_translations");

                foreach ($locales as $locale) {
                    if (! is_array($translations) || ! TranslatableField::isPresent($translations[$locale] ?? null)) {
                        $this->tally('skipped_unknown');
                    }
                }
            }
        }
    }

    /**
     * One repository value against the one row that holds it.
     *
     * @template TModel of Model
     *
     * @param  list<string>  $locales
     * @param  \Closure(): Builder<TModel>  $query
     */
    private function model(RepositoryTranslation $entry, array $locales, \Closure $query): void
    {
        if ($entry->reference() === null) {
            return;
        }

        DB::transaction(function () use ($entry, $locales, $query): void {
            $model = $query()->lockForUpdate()->first();

            if ($model === null) {
                // Never created here: content the project does not have stays absent.
                $this->tally('skipped_unknown', count($locales));

                return;
            }

            $column = "{$entry->field}_translations";
            $filled = $this->fill($entry, $model->getAttribute($entry->field), $model->getAttribute($column), $locales);

            if ($filled !== null) {
                $model->forceFill([$column => $filled])->save();
            }
        });
    }

    /**
     * The rules for one field: the translations to store, or null when
     * nothing changes.
     *
     * @param  list<string>  $locales
     * @return array<string, mixed>|null
     */
    private function fill(RepositoryTranslation $entry, mixed $base, mixed $translations, array $locales): ?array
    {
        $reference = $entry->reference();

        if ($reference === null) {
            return null;
        }

        if ($translations !== null && ! is_array($translations)) {
            // Not a list of translations at all: nothing here can be added safely.
            $this->tally('skipped_unknown', count($locales));

            return null;
        }

        $translations ??= [];
        $changed = false;

        foreach ($locales as $locale) {
            if (TranslatableField::isPresent($translations[$locale] ?? null)) {
                $this->tally('already_present');
            } elseif ($entry->value($locale) === null) {
                $this->tally('skipped_unknown');
            } elseif ($base !== $reference) {
                $this->tally('skipped_customized');
            } else {
                $translations[$locale] = $entry->value($locale);
                $this->tally('filled');
                $changed = true;
            }
        }

        return $changed ? $translations : null;
    }

    private function tally(string $outcome, int $by = 1): void
    {
        $this->counts[$outcome] += $by;
    }
}
