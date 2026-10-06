<?php

namespace App\Filament\Pages;

use App\Actions\Settings\UpdateProjectLocaleSettingsAction;
use App\Exceptions\Settings\IncompleteLocaleCatalogException;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\TranslationSourceEditor;
use App\Support\Locale\LocaleManager;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCompleteness;
use App\Support\Translations\ProjectTranslationReport;
use App\Support\Translations\ProjectTranslationUnit;
use App\Support\Translations\TranslatableField;
use App\Support\Translations\TranslationCatalogInspector;
use App\Support\Translations\TranslationCatalogIssue;
use App\Support\Translations\TranslationCatalogReport;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Every installed language, whether this project offers it, and how complete
 * it is — the place a language is enabled or disabled.
 *
 * Three separate facts per language:
 *
 *  - installed: shipped by the release (config/locales.php + lang/{code}/);
 *  - complete: the application catalogs, held to the same contract CI holds
 *    them to, and this project's own content as the database holds it now;
 *  - enabled: whether visitors are offered it.
 *
 * English is the default by system policy: always enabled, never disabled,
 * and nothing here makes another language the default.
 *
 * The page only reads; every change goes through
 * UpdateProjectLocaleSettingsAction, which also refuses to enable a language
 * whose application catalogs break the contract. One whose project content is
 * missing translations can, after a warning: visitors then see English where
 * a translation is missing, which an administrator may accept.
 *
 * Drawn entirely in Admin v2: the view renders the whole screen itself rather
 * than inside Filament's page wrapper, so Filament's own heading, table and
 * action modals are not on it. Confirmations, the missing-translations drawer
 * and the status tab are Livewire state; the search is the browser's, over the
 * rows already on the page. Every public method checks the
 * language against what is installed and what is offered now, because what
 * the browser sends is not a permission.
 *
 * @phpstan-type LanguageRow array{code: string, flag: string, label: string, native: string, enabled: bool, default: bool, reference: bool, catalog: TranslationCatalogReport, project: ProjectTranslationReport, missing: int, incomplete: bool, search: string}
 */
final class LanguagesPage extends Page
{
    /** The status tabs in order; the first is the default. */
    public const STATUSES = ['all', 'enabled', 'disabled', 'incomplete'];

    /** How many catalog issues the drawer lists before it counts the rest. */
    public const LISTED_ISSUES = 50;

    protected string $view = 'filament.pages.languages';

    // A screen drawn in Admin v2 brings its own header band and 28px gutter, so
    // it takes the whole main column rather than Filament's padded content
    // width. A string here is the class Filament puts on its <main>.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::LOCALIZATION;

    protected static ?string $navigationLabel = 'Languages';

    protected static ?string $title = 'Languages';

    protected static ?string $slug = 'languages';

    protected static ?int $navigationSort = 10;

    /**
     * The status tab, in the query string so it survives a reload and moves
     * with Back. Typed loosely on purpose: whatever a link or a forged update
     * puts here is read back as a tab, never as an error.
     */
    #[Url(history: true, except: 'all')]
    public mixed $status = 'all';

    /** The confirmation on screen: enable or disable. */
    #[Locked]
    public ?string $confirming = null;

    /** The language the confirmation on screen is about. */
    #[Locked]
    public ?string $confirmingLocale = null;

    /** The language whose missing translations the drawer lists. */
    #[Locked]
    public ?string $missingLocale = null;

    /*
     * What one request read, so the header, the tabs, the table, the drawer
     * and the confirmation share a single read of the catalogs and of the
     * project content. Nothing is kept between requests.
     */

    /** @var array<string, TranslationCatalogReport>|null */
    private ?array $catalogReports = null;

    /** @var array<string, ProjectTranslationReport>|null */
    private ?array $projectReports = null;

    /** @var array<string, LanguageRow>|null */
    private ?array $rows = null;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-project-settings');
    }

    public function mount(): void
    {
        $this->updatedStatus();
    }

    /** A status that is not a tab shows them all. */
    public function updatedStatus(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            $this->status = 'all';
        }
    }

    public function askToEnable(mixed $locale = null): void
    {
        $this->closeConfirmation();
        $row = $this->row($locale);

        if ($row === null) {
            $this->refuseUnknownLanguage();

            return;
        }

        if ($row['enabled']) {
            $this->toast("{$row['label']} is already enabled.", 'info');

            return;
        }

        // A release with broken catalogs should never have passed CI; if one
        // reaches a server anyway, its language stays off. The row already
        // says so; this keeps a stale or forged click from offering a dialog.
        if (! $row['catalog']->isComplete()) {
            $this->refuseBrokenCatalog($row['label']);

            return;
        }

        $this->confirming = 'enable';
        $this->confirmingLocale = $row['code'];
    }

    public function askToDisable(mixed $locale = null): void
    {
        $this->closeConfirmation();
        $row = $this->row($locale);

        if ($row === null) {
            $this->refuseUnknownLanguage();

            return;
        }

        if ($row['default']) {
            $this->refuseDefault($row['label']);

            return;
        }

        if (! $row['enabled']) {
            $this->toast("{$row['label']} is already disabled.", 'info');

            return;
        }

        $this->confirming = 'disable';
        $this->confirmingLocale = $row['code'];
    }

    /**
     * Offers a language to visitors. Its catalogs are checked by the action
     * itself, whoever calls this and whatever the page showed: a broken one
     * is refused there, and the row is read again so it says why.
     */
    public function enableLanguage(mixed $locale = null): void
    {
        $this->closeConfirmation();
        $locales = app(LocaleManager::class);

        if (! is_string($locale) || ! $locales->isSupported($locale)) {
            $this->refuseUnknownLanguage();

            return;
        }

        $label = $locales->label($locale);

        if ($locales->isEnabled($locale)) {
            $this->toast("{$label} is already enabled.", 'info');

            return;
        }

        try {
            $this->offer([...$locales->enabledCodes(), $locale]);
        } catch (IncompleteLocaleCatalogException) {
            $this->catalogReports = null;
            $this->rows = null;
            $this->refuseBrokenCatalog($label);

            return;
        }

        $this->toast("{$label} enabled");
    }

    /**
     * Stops offering a language. Nothing stored is touched: its translations
     * stay, and so does every visitor's preference for it, which applies
     * again once it is enabled. Until then such a visitor gets their
     * browser's language if it is offered, otherwise English.
     */
    public function disableLanguage(mixed $locale = null): void
    {
        $this->closeConfirmation();
        $locales = app(LocaleManager::class);

        if (! is_string($locale) || ! $locales->isSupported($locale)) {
            $this->refuseUnknownLanguage();

            return;
        }

        $label = $locales->label($locale);

        // The default is always enabled: it has nothing to disable.
        if ($locales->isDefault($locale)) {
            $this->refuseDefault($label);

            return;
        }

        if (! $locales->isEnabled($locale)) {
            $this->toast("{$label} is already disabled.", 'info');

            return;
        }

        $this->offer(array_values(array_diff($locales->enabledCodes(), [$locale])));

        $this->toast("{$label} disabled");
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
        $this->confirmingLocale = null;
    }

    /** Opens the drawer for a language; from the enable warning it replaces the dialog and enables nothing. */
    public function showMissing(mixed $locale = null): void
    {
        $this->closeConfirmation();
        $row = $this->row($locale);

        if ($row === null) {
            $this->refuseUnknownLanguage();

            return;
        }

        $this->missingLocale = $row['code'];
    }

    public function closeMissing(): void
    {
        $this->missingLocale = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = $this->languages();
        $status = in_array($this->status, self::STATUSES, true) ? $this->status : 'all';
        $query = self::requestedQuery();
        $locales = app(LocaleManager::class);

        return [
            'stats' => $this->stats($rows),
            'tabs' => $this->tabs($rows, $query),
            'status' => $status,
            'query' => $query,
            // Only the tab is decided here. The search filters these rows in the
            // browser: the installed languages are a bounded list already on the
            // page, and a keystroke must not cost a fresh read of every catalog
            // and of the project's content.
            'rows' => array_filter($rows, fn (array $row): bool => self::shows($status, $row)),
            'confirmation' => $this->confirmation($rows),
            'missing' => $this->missing($rows),
            'defaultLabel' => $locales->label($locales->default()),
            'referenceLabel' => $locales->label(TranslatableField::REFERENCE_LOCALE),
            'referenceCode' => TranslatableField::REFERENCE_LOCALE,
        ];
    }

    /**
     * Writes the offered languages. Only whether each language is offered
     * changed, so only the rows are read again; the catalogs and the project
     * content this request read still hold, and the response already shows
     * the new state — the row and its action — without a reload.
     *
     * @param  list<string>  $enabled
     */
    private function offer(array $enabled): void
    {
        app(UpdateProjectLocaleSettingsAction::class)->handle($enabled);
        $this->rows = null;
    }

    private function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone);
    }

    private function refuseUnknownLanguage(): void
    {
        $this->toast('That language is not installed.', 'error');
    }

    private function refuseDefault(string $label): void
    {
        $this->toast("{$label} is the default language and is always enabled.", 'error');
    }

    private function refuseBrokenCatalog(string $label): void
    {
        $this->toast("{$label} cannot be enabled: its application translations break the catalog contract.", 'error');
    }

    /**
     * Whether a row belongs under a status tab. Incomplete is either half of
     * completeness: an application catalog that breaks the contract, or
     * project content without a translation.
     *
     * @param  LanguageRow  $row
     */
    private static function shows(string $status, array $row): bool
    {
        return match ($status) {
            'enabled' => $row['enabled'],
            'disabled' => ! $row['enabled'],
            'incomplete' => $row['incomplete'],
            default => true,
        };
    }

    /**
     * The search the page was opened with (`?q=`), so the tab links of the first
     * response carry it before the browser takes over. A Livewire round trip
     * has no query string of its own; the browser keeps the links current.
     */
    private static function requestedQuery(): string
    {
        $query = request()->query('q');

        return is_string($query) ? Str::limit(trim($query), 100, '') : '';
    }

    /**
     * The header's figures. Project translations and Missing are about the
     * languages content is translated into — every installed language but the
     * reference, enabled or not, since a disabled language can be prepared
     * before it is offered. The percentage is weighted by what each language
     * has to translate and rounded down, as the reports round.
     *
     * @param  array<string, LanguageRow>  $rows
     * @return array{installed: int, enabled: int, translated: int, missing: int}
     */
    private function stats(array $rows): array
    {
        $targets = array_filter($rows, fn (array $row): bool => ! $row['reference']);
        $required = array_sum(array_map(fn (array $row): int => $row['project']->required, $targets));
        $translated = array_sum(array_map(fn (array $row): int => $row['project']->translated, $targets));

        return [
            'installed' => count($rows),
            'enabled' => count(app(LocaleManager::class)->enabledCodes()),
            'translated' => $required === 0 ? 100 : intdiv($translated * 100, $required),
            'missing' => array_sum(array_map(fn (array $row): int => $row['missing'], $targets)),
        ];
    }

    /**
     * One tab per status. Each count is over every installed language, so
     * choosing a tab never changes the others' counts.
     *
     * Each is a real link, search included, so it opens in a new tab and copies
     * as what it shows; the browser keeps its search current as it changes, and
     * a click switches the tab in place.
     *
     * @param  array<string, LanguageRow>  $rows
     * @return list<array{id: string, label: string, count: int, href: string, attributes: array<string, string>}>
     */
    private function tabs(array $rows, string $query): array
    {
        return array_map(fn (string $status): array => [
            'id' => $status,
            'label' => ucfirst($status),
            'count' => count(array_filter($rows, fn (array $row): bool => self::shows($status, $row))),
            'href' => self::getUrl(array_filter(['status' => $status === 'all' ? null : $status, 'q' => $query === '' ? null : $query])),
            'attributes' => [
                'wire:click.prevent' => "\$set('status', '{$status}')",
                'x-bind:href' => 'withQuery('.Js::from(self::getUrl($status === 'all' ? [] : ['status' => $status])).')',
            ],
        ], self::STATUSES);
    }

    /**
     * The confirmation on screen, while it still applies to the language as
     * it is now.
     *
     * @param  array<string, LanguageRow>  $rows
     * @return array{action: string, row: LanguageRow}|null
     */
    private function confirmation(array $rows): ?array
    {
        $row = $rows[$this->confirmingLocale ?? ''] ?? null;

        $applies = $row !== null && match ($this->confirming) {
            'enable' => ! $row['enabled'] && $row['catalog']->isComplete(),
            'disable' => $row['enabled'] && ! $row['default'],
            default => false,
        };

        return $applies ? ['action' => (string) $this->confirming, 'row' => $row] : null;
    }

    /**
     * What the drawer shows for its language: the catalog issues, the first
     * LISTED_ISSUES of them, and the missing project content by section, with
     * Translation Center opened on that language's missing items when there
     * are any. Catalog issues are the release's to fix: Translation Center
     * edits project content only, so they never lead there.
     *
     * @param  array<string, LanguageRow>  $rows
     * @return array{row: LanguageRow, issues: list<string>, unlisted: int, sections: list<array{label: string, items: list<array{label: string, field: ?string, reference: string, url: string, translate: string}>}>, translateAll: ?string}|null
     */
    private function missing(array $rows): ?array
    {
        $row = $rows[$this->missingLocale ?? ''] ?? null;

        if ($row === null) {
            return null;
        }

        $issues = $row['catalog']->issues;

        return [
            'row' => $row,
            'issues' => array_map(fn (TranslationCatalogIssue $issue): string => $issue->message, array_slice($issues, 0, self::LISTED_ISSUES)),
            'unlisted' => max(0, count($issues) - self::LISTED_ISSUES),
            'sections' => $this->missingSections($row['project']),
            'translateAll' => $row['missing'] > 0 ? TranslationCenterPage::getUrl(['locale' => $row['code'], 'mode' => 'missing']) : null,
        ];
    }

    /**
     * The row of the installed language a request names, or null for anything
     * else it might send: an unknown code, nothing, or not a string at all.
     *
     * @return LanguageRow|null
     */
    private function row(mixed $locale): ?array
    {
        return is_string($locale) ? ($this->languages()[$locale] ?? null) : null;
    }

    /**
     * One row per installed language, in config order.
     *
     * @return array<string, LanguageRow>
     */
    private function languages(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $locales = app(LocaleManager::class);
        $enabled = $locales->enabledCodes();
        $catalogs = $this->catalogReports();
        $projects = $this->projectReports();
        $rows = [];

        foreach ($locales->supported() as $code => $info) {
            $rows[$code] = [
                'code' => $code,
                'flag' => $info['flag'],
                'label' => $info['label'],
                'native' => $info['native'],
                'enabled' => in_array($code, $enabled, true),
                'default' => $locales->isDefault($code),
                // What every other language is translated from.
                'reference' => $code === TranslatableField::REFERENCE_LOCALE,
                'catalog' => $catalogs[$code],
                'project' => $projects[$code],
                'missing' => count($projects[$code]->missing),
                'incomplete' => ! $catalogs[$code]->isComplete() || ! $projects[$code]->isComplete(),
                // What the browser's search looks in: English name, native name and code.
                'search' => mb_strtolower("{$info['label']} {$info['native']} {$code}"),
            ];
        }

        return $this->rows = $rows;
    }

    /** @return array<string, TranslationCatalogReport> */
    private function catalogReports(): array
    {
        if ($this->catalogReports !== null) {
            return $this->catalogReports;
        }

        $inspector = app(TranslationCatalogInspector::class);

        return $this->catalogReports = collect(array_keys(app(LocaleManager::class)->supported()))
            ->mapWithKeys(fn (string $code): array => [$code => $inspector->inspect($code)])
            ->all();
    }

    /** @return array<string, ProjectTranslationReport> */
    private function projectReports(): array
    {
        return $this->projectReports ??= app(ProjectTranslationCompleteness::class)->reports(array_keys(app(LocaleManager::class)->supported()));
    }

    /**
     * The missing translations by section, in the sections' own order. Each
     * is named by what it is and which of its fields — the field left out
     * where it would only repeat the name, as for a project setting — with
     * the start of the English text it is translated from, Translate, which
     * opens Translation Center on that item, and Edit source, which opens the
     * editor that holds its English text.
     *
     * @return list<array{label: string, items: list<array{label: string, field: ?string, reference: string, url: string, translate: string}>}>
     */
    private function missingSections(ProjectTranslationReport $report): array
    {
        $sections = [];

        foreach ($report->missingBySection() as $section => $units) {
            $sections[] = [
                'label' => ProjectContentSection::from($section)->label(),
                'items' => array_map(fn (ProjectTranslationUnit $unit): array => [
                    'label' => $unit->label,
                    'field' => $unit->fieldLabel(),
                    'reference' => Str::limit(Str::squish(strip_tags($unit->reference)), 120),
                    'url' => TranslationSourceEditor::url($unit),
                    // The unit is where Translation Center opens, nothing more: what it
                    // saves is the unit it finds again for itself.
                    'translate' => TranslationCenterPage::getUrl(['locale' => $report->locale, 'section' => $section, 'mode' => 'missing', 'unit' => $unit->id]),
                ], $units),
            ];
        }

        return $sections;
    }
}
