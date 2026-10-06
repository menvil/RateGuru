<?php

namespace App\Filament\Pages;

use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Exceptions\Translations\CannotSaveTranslationException;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\TranslationSourceEditor;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationCompleteness;
use App\Support\Translations\ProjectTranslationReport;
use App\Support\Translations\ProjectTranslationUnit;
use App\Support\Translations\TranslatableField;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Where this project's own content is translated: one target language at a
 * time, every translatable field of the project in one list — English
 * reference beside the translation — saved one field at a time.
 *
 * What is listed is ProjectTranslationCatalog's, the same units Languages
 * counts, so a missing translation Languages reports is one this page can
 * fill. Each translation is written where the content already keeps it, so
 * the editors that still translate in place show it too, and the other way
 * round; there is no second copy to keep in step.
 *
 * Drawn entirely in Admin v2. The target language is the page's one piece of
 * server state, in the URL (`?locale=`): choosing another reads that
 * language's translations afresh. Everything else is the browser's, over the
 * units already on the page — the search, the section, Missing only / All
 * (kept in the URL with replaceState), and the drafts, which exist only in the
 * browser until they are saved. Typing sends nothing; Save sends one unit id,
 * the language and the text, and the context drawer asks for one unit's
 * details when it opens. Neither re-renders the page, so drafts in other rows
 * stay as they are.
 *
 * Every method checks what the browser sends against the catalog and the
 * installed languages now; UpdateProjectTranslationAction is the final
 * safeguard for a write.
 *
 * @phpstan-type TargetLanguage array{code: string, label: string, native: string, flag: string, enabled: bool}
 */
final class TranslationCenterPage extends Page
{
    /** Missing only / All, as the URL carries them; All is the default. */
    public const MODES = ['all', 'missing'];

    /** How long a search kept in the URL may be. */
    public const QUERY_LIMIT = 100;

    protected string $view = 'filament.pages.translation-center';

    // A screen drawn in Admin v2 brings its own header band and 28px gutter, so
    // it takes the whole main column rather than Filament's padded content
    // width. A string here is the class Filament puts on its <main>.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::LOCALIZATION;

    protected static ?string $navigationLabel = 'Translation Center';

    protected static ?string $title = 'Translation Center';

    protected static ?string $slug = 'translation-center';

    protected static ?int $navigationSort = 20;

    /**
     * The target language, always in the query string so a link or a reload
     * opens the same one. Typed loosely on purpose: whatever a link or a
     * forged update puts here is read back as a target language, never as an
     * error. Replaced rather than pushed: Back leaves the page, where the
     * browser asks before drafts are lost, instead of switching the language
     * under them.
     */
    #[Url(keep: true)]
    public mixed $locale = null;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-project-settings');
    }

    public function mount(): void
    {
        $this->updatedLocale();
    }

    /** Anything that is not a target language opens the default one. */
    public function updatedLocale(): void
    {
        $this->locale = self::target($this->locale);
    }

    /**
     * Saves one draft. The unit, the language and the text are all the
     * browser sends; the action finds the unit again and checks all three.
     * Nothing on the page is re-rendered: the browser updates the row from
     * what this returns.
     *
     * @return array{saved: true, value: string}|array{saved: false, error: string, field: bool}
     */
    #[Renderless]
    public function save(mixed $unit = null, mixed $locale = null, mixed $text = null): array
    {
        $user = auth()->user();

        try {
            if (! $user instanceof User) {
                throw CannotSaveTranslationException::becauseUserIsNotAllowed();
            }

            $saved = app(UpdateProjectTranslationAction::class)->handle($user, $unit, $locale, $text);
        } catch (CannotSaveTranslationException $exception) {
            return ['saved' => false, 'error' => $exception->getMessage(), 'field' => $exception->isAboutTheText()];
        }

        return ['saved' => true, 'value' => $saved->translation((string) $locale) ?? ''];
    }

    /**
     * What the context drawer shows for one unit, read when it opens — the
     * unit's row only, never every language of every unit up front. Null for
     * a unit or language that is not one now.
     *
     * @return array<string, mixed>|null
     */
    #[Renderless]
    public function context(mixed $unit = null, mixed $locale = null): ?array
    {
        $target = self::targets()[is_string($locale) ? $locale : ''] ?? null;
        $found = app(ProjectTranslationCatalog::class)->find($unit);

        if ($target === null || $found === null || ! $found->requiresTranslation()) {
            return null;
        }

        $others = [];

        foreach (self::targets() as $code => $language) {
            $text = $found->translation($code);

            if ($code !== $target['code'] && $text !== null) {
                $others[] = ['code' => $code, 'flag' => $language['flag'], 'label' => "{$language['label']} — {$language['native']}", 'text' => $text];
            }
        }

        return [
            'id' => $found->id,
            'subtitle' => self::name($found),
            'section' => $found->section->label(),
            'entity' => $found->label,
            'field' => self::fieldName($found),
            'key' => $found->qualifiedKey(),
            'reference' => $found->reference,
            'target' => "{$target['label']} — {$target['native']} · {$target['code']}",
            'targetEnabled' => $target['enabled'],
            'usage' => $found->usage,
            'max' => number_format($found->maxLength).' characters',
            'format' => $found->multiline ? 'Multiline' : 'Single line',
            'placeholders' => $found->placeholders(),
            'sourceUrl' => TranslationSourceEditor::url($found),
            'others' => $others,
        ];
    }

    /**
     * Every installed language but English — the reference everything is
     * translated from — in config order: enabled or not, since a language can
     * be prepared before it is offered.
     *
     * @return array<string, TargetLanguage>
     */
    public static function targets(): array
    {
        $locales = app(LocaleManager::class);
        $enabled = $locales->enabledCodes();
        $targets = [];

        foreach ($locales->supported() as $code => $info) {
            if ($code !== TranslatableField::REFERENCE_LOCALE) {
                $targets[$code] = [
                    'code' => $code,
                    'label' => $info['label'],
                    'native' => $info['native'],
                    'flag' => $info['flag'],
                    'enabled' => in_array($code, $enabled, true),
                ];
            }
        }

        return $targets;
    }

    /**
     * The target language a request names, or the one the page opens on: the
     * first enabled target, else the first installed one, else none at all.
     */
    public static function target(mixed $locale): ?string
    {
        $targets = self::targets();

        if (is_string($locale) && array_key_exists($locale, $targets)) {
            return $locale;
        }

        foreach ($targets as $code => $target) {
            if ($target['enabled']) {
                return $code;
            }
        }

        return array_key_first($targets);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $targets = self::targets();
        $locale = self::target($this->locale);

        if ($locale === null) {
            return ['target' => null];
        }

        $units = app(ProjectTranslationCatalog::class)->units();
        $reports = app(ProjectTranslationCompleteness::class)->reportsFor($units, array_keys($targets));
        $report = $reports[$locale];
        $rows = $this->rows($units, $locale);
        $filters = self::requestedFilters();

        return [
            'target' => $targets[$locale],
            'options' => $this->targetOptions($targets, $reports, $locale),
            'stats' => [
                'total' => $report->required,
                'translated' => $report->translated,
                'missing' => count($report->missing),
                'percentage' => $report->percentage(),
            ],
            'sections' => $this->sectionOptions($rows),
            'rows' => $rows,
            'filters' => $filters,
            'client' => [
                'locale' => $locale,
                'label' => $targets[$locale]['label'],
                'targets' => array_map(fn (array $target): string => $target['label'], $targets),
                'sections' => array_map(fn (ProjectContentSection $section): string => $section->value, ProjectContentSection::cases()),
                'queryLimit' => self::QUERY_LIMIT,
                'units' => array_map(fn (array $row): array => [
                    'id' => $row['id'],
                    'dom' => $row['dom'],
                    'section' => $row['sectionValue'],
                    'max' => $row['max'],
                    'multiline' => $row['multiline'],
                    'placeholders' => $row['placeholders'],
                    'stored' => $row['stored'],
                    'search' => $row['search'],
                ], $rows),
            ],
        ];
    }

    /**
     * One row per unit that needs a translation, in the catalog's order, with
     * the target language's stored text — what the page draws and what the
     * browser filters and edits.
     *
     * @param  list<ProjectTranslationUnit>  $units
     * @return list<array<string, mixed>>
     */
    private function rows(array $units, string $locale): array
    {
        $rows = [];

        foreach (array_values(array_filter($units, fn (ProjectTranslationUnit $unit): bool => $unit->requiresTranslation())) as $index => $unit) {
            $rows[] = [
                'id' => $unit->id,
                'dom' => "rg-admin-tr-{$index}",
                'section' => $unit->section->label(),
                'sectionValue' => $unit->section->value,
                'entity' => $unit->label,
                'field' => $unit->fieldLabel(),
                'name' => self::name($unit),
                'key' => $unit->qualifiedKey(),
                'reference' => $unit->reference,
                'referenceLength' => mb_strlen($unit->reference),
                'max' => $unit->maxLength,
                'multiline' => $unit->multiline,
                'placeholders' => $unit->placeholders(),
                'stored' => $unit->translation($locale) ?? '',
                'sourceUrl' => TranslationSourceEditor::url($unit),
                // What the browser's search looks in besides the translation: the
                // English text, the content's name, its field, its keys and its id.
                'search' => mb_strtolower(implode(' ', [$unit->reference, $unit->label, self::fieldName($unit), $unit->qualifiedKey(), $unit->key, $unit->id])),
            ];
        }

        return $rows;
    }

    /**
     * The target language combobox: every target, its state and how much of
     * the project it translates, from the units this request already read.
     * The figures of the language being translated follow its saves.
     *
     * @param  array<string, TargetLanguage>  $targets
     * @param  array<string, ProjectTranslationReport>  $reports
     * @return list<array<string, mixed>>
     */
    private function targetOptions(array $targets, array $reports, string $locale): array
    {
        return array_values(array_map(function (array $target) use ($reports, $locale): array {
            $state = $target['code'].' · '.($target['enabled'] ? 'enabled' : 'disabled').' · ';
            $report = $reports[$target['code']];

            return [
                'value' => $target['code'],
                'label' => "{$target['label']} — {$target['native']}",
                'leading' => $target['flag'],
                'meta' => $state.number_format(count($report->missing)).' missing',
                'trailing' => $report->percentage().'%',
                'trailingTone' => $report->isComplete() ? 'success' : null,
                'badge' => $target['enabled']
                    ? ['label' => 'Enabled', 'tone' => 'success', 'dot' => true]
                    : ['label' => 'Disabled', 'tone' => 'neutral', 'dot' => false],
                'search' => "{$target['label']} {$target['native']} {$target['code']}",
                'live' => $target['code'] === $locale
                    ? ['meta' => Js::from($state).' + figure(missing) + \' missing\'', 'trailing' => "percentage + '%'", 'complete' => 'percentage === 100']
                    : null,
            ];
        }, $targets));
    }

    /**
     * The section filter: all sections, then each section in its own order
     * with how many of its units are missing — counted again in the browser as
     * translations are saved.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sectionOptions(array $rows): array
    {
        $options = [['value' => '', 'label' => 'All sections', 'trigger' => 'All']];

        foreach (ProjectContentSection::cases() as $section) {
            $missing = count(array_filter($rows, fn (array $row): bool => $row['sectionValue'] === $section->value && $row['stored'] === ''));

            $options[] = [
                'value' => $section->value,
                'label' => $section->label(),
                'count' => "{$missing} missing",
                'liveCount' => "missingIn('{$section->value}') + ' missing'",
            ];
        }

        return $options;
    }

    /**
     * The filters the page was opened with, so its first response draws them
     * before the browser takes over: a Livewire round trip has no query string
     * of its own, and the browser keeps the URL current from then on.
     *
     * @return array{query: string, section: string, mode: string, unit: ?string}
     */
    private static function requestedFilters(): array
    {
        $query = request()->query('q');
        $section = request()->query('section');
        $mode = request()->query('mode');
        $unit = request()->query('unit');

        return [
            'query' => is_string($query) ? Str::limit(trim($query), self::QUERY_LIMIT, '') : '',
            'section' => is_string($section) && ProjectContentSection::tryFrom($section) !== null ? $section : '',
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'all',
            'unit' => is_string($unit) ? $unit : null,
        ];
    }

    /** What a unit is called: “Food · Name”, or a setting by its name alone. */
    private static function name(ProjectTranslationUnit $unit): string
    {
        return $unit->label.($unit->fieldLabel() !== null ? ' · '.$unit->fieldLabel() : '');
    }

    /** The field a unit is, for a person: “Name”, or the setting itself. */
    private static function fieldName(ProjectTranslationUnit $unit): string
    {
        return $unit->fieldLabel() ?? ucfirst(str_replace('_', ' ', $unit->field));
    }
}
