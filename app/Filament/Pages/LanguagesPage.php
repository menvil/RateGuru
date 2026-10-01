<?php

namespace App\Filament\Pages;

use App\Actions\Settings\UpdateProjectLocaleSettingsAction;
use App\Exceptions\Settings\IncompleteLocaleCatalogException;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Support\Locale\LocaleManager;
use App\Support\Translations\MissingProjectTranslation;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCompleteness;
use App\Support\Translations\ProjectTranslationReport;
use App\Support\Translations\TranslationCatalogInspector;
use App\Support\Translations\TranslationCatalogReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
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
 */
final class LanguagesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.languages';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::SYSTEM;

    protected static ?string $navigationLabel = 'Languages';

    protected static ?string $title = 'Languages';

    protected static ?string $slug = 'languages';

    protected static ?int $navigationSort = 11;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rows = null;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-project-settings');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->languages())
            ->paginated(false)
            ->columns([
                TextColumn::make('label')
                    ->label('Language')
                    ->formatStateUsing(fn (string $state, array $record): string => "{$record['flag']} {$state}")
                    ->description(fn (array $record): string => $record['native']),
                TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (array $record): string => $record['enabled'] ? 'Enabled' : 'Disabled')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Enabled' ? 'success' : 'gray')
                    ->description(fn (array $record): ?string => $record['default'] ? 'Default' : null)
                    ->tooltip(fn (array $record): ?string => $record['default'] ? "{$record['label']} is always enabled and is the default language." : null),
                TextColumn::make('application')
                    ->label('Application')
                    ->state(fn (array $record): string => "{$record['application']}%")
                    ->badge()
                    ->color(fn (array $record): string => $record['application_complete'] ? 'success' : 'danger')
                    ->description(fn (array $record): ?string => $record['application_complete'] ? null : "{$record['application_issues']} catalog issue(s)"),
                TextColumn::make('project')
                    ->label('Project content')
                    ->state(fn (array $record): string => "{$record['project']}%")
                    ->badge()
                    ->color(fn (array $record): string => $record['project_missing'] === 0 ? 'success' : 'warning')
                    ->description(fn (array $record): ?string => $record['project_missing'] === 0 ? null : "{$record['project_missing']} missing"),
            ])
            ->recordActions([
                $this->enableAction(),
                $this->disableAction(),
                $this->missingTranslationsAction(),
            ]);
    }

    private function enableAction(): Action
    {
        return Action::make('enable')
            ->label('Enable')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (array $record): bool => ! $record['enabled'])
            // A release with broken catalogs should never have passed CI; if one
            // reaches a server anyway, its language stays off. The action
            // refuses it whoever asks; this only says so before the click.
            ->disabled(fn (array $record): bool => ! $record['application_complete'])
            ->tooltip(fn (array $record): ?string => $record['application_complete'] ? null : 'Its application translations break the catalog contract. Fix the release first.')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => "Enable {$record['label']}?")
            ->modalDescription(fn (array $record): string => $record['project_missing'] === 0
                ? "{$record['label']} has complete project translations and will become available to visitors."
                : "{$record['label']} has {$record['project_missing']} missing project ".($record['project_missing'] === 1 ? 'translation' : 'translations').". Visitors may see {$this->defaultLabel()} fallback content.")
            ->modalSubmitActionLabel(fn (array $record): string => $record['project_missing'] === 0 ? 'Enable' : 'Enable anyway')
            ->action(function (array $record): void {
                try {
                    $this->updateLocales([...app(LocaleManager::class)->enabledCodes(), $record['code']]);
                } catch (IncompleteLocaleCatalogException) {
                    $this->refuse("{$record['label']} cannot be enabled: its application translations break the catalog contract.");

                    return;
                }

                Notification::make()->title("{$record['label']} enabled")->success()->send();
            });
    }

    private function disableAction(): Action
    {
        return Action::make('disable')
            ->label('Disable')
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            // The default is always enabled: it has nothing to disable.
            ->visible(fn (array $record): bool => $record['enabled'] && ! $record['default'])
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => "Disable {$record['label']}?")
            ->modalDescription(fn (array $record): string => "Visitors currently using {$record['label']} will fall back to {$this->defaultLabel()}. Their {$record['label']} preference is kept and will apply again if {$record['label']} is enabled later.")
            ->action(function (array $record): void {
                $locales = app(LocaleManager::class);

                if ($locales->isDefault($record['code'])) {
                    $this->refuse("{$record['label']} is the default language and is always enabled.");

                    return;
                }

                $this->updateLocales(array_values(array_diff($locales->enabledCodes(), [$record['code']])));

                Notification::make()->title("{$record['label']} disabled")->success()->send();
            });
    }

    private function missingTranslationsAction(): Action
    {
        return Action::make('missingTranslations')
            ->label('View missing translations')
            ->icon('heroicon-o-list-bullet')
            ->color('gray')
            ->visible(fn (array $record): bool => $record['project_missing'] > 0 || ! $record['application_complete'])
            ->slideOver()
            ->modalHeading(fn (array $record): string => "{$record['label']} — {$record['project']}% of project content")
            ->modalContent(fn (array $record): View => view('filament.pages.languages-missing', [
                'catalog' => $this->catalogReports()[$record['code']],
                'sections' => $this->missingSections($this->projectReports()[$record['code']]),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Writes the offered languages, then drops what this request already read:
     * the rows built here and the records Filament cached for the table while
     * it resolved the clicked row. Without both, the response would render the
     * state from before the change — the row still Enabled, its old action
     * still showing — until a full page reload.
     *
     * @param  list<string>  $enabled
     */
    private function updateLocales(array $enabled): void
    {
        app(UpdateProjectLocaleSettingsAction::class)->handle($enabled);
        $this->rows = null;
        $this->flushCachedTableRecords();
    }

    private function defaultLabel(): string
    {
        $locales = app(LocaleManager::class);

        return $locales->label($locales->default());
    }

    private function refuse(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }

    /**
     * One row per installed language, in config order.
     *
     * @return array<string, array<string, mixed>>
     */
    private function languages(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $locales = app(LocaleManager::class);
        $catalogs = $this->catalogReports();
        $projects = $this->projectReports();
        $rows = [];

        foreach ($locales->supported() as $code => $info) {
            $rows[$code] = [
                'code' => $code,
                'flag' => $info['flag'],
                'label' => $info['label'],
                'native' => $info['native'],
                'enabled' => $locales->isEnabled($code),
                'default' => $locales->isDefault($code),
                'application' => $catalogs[$code]->percentage(),
                'application_complete' => $catalogs[$code]->isComplete(),
                'application_issues' => count($catalogs[$code]->issues),
                'project' => $projects[$code]->percentage(),
                'project_missing' => count($projects[$code]->missing),
            ];
        }

        return $this->rows = $rows;
    }

    /** @return array<string, TranslationCatalogReport> */
    private function catalogReports(): array
    {
        $inspector = app(TranslationCatalogInspector::class);

        return collect(array_keys(app(LocaleManager::class)->supported()))
            ->mapWithKeys(fn (string $code): array => [$code => $inspector->inspect($code)])
            ->all();
    }

    /** @return array<string, ProjectTranslationReport> */
    private function projectReports(): array
    {
        return app(ProjectTranslationCompleteness::class)->reports(array_keys(app(LocaleManager::class)->supported()));
    }

    /**
     * The missing translations by section, each with a link to the editor
     * that already manages that content.
     *
     * @return list<array{label: string, items: list<array{label: string, field: string, url: string}>}>
     */
    private function missingSections(ProjectTranslationReport $report): array
    {
        $sections = [];

        foreach ($report->missingBySection() as $section => $items) {
            $sections[] = [
                'label' => ProjectContentSection::from($section)->label(),
                'items' => array_map(fn (MissingProjectTranslation $item): array => [
                    'label' => $item->label,
                    'field' => $item->field,
                    'url' => $this->editUrl($item),
                ], $items),
            ];
        }

        return $sections;
    }

    private function editUrl(MissingProjectTranslation $item): string
    {
        return match ($item->section) {
            ProjectContentSection::ProjectSettings, ProjectContentSection::StaticPages => ProjectSettingsPage::getUrl(),
            ProjectContentSection::Categories => CategoryResource::getUrl('edit', ['record' => $item->recordId]),
            ProjectContentSection::Tags => TagResource::getUrl('edit', ['record' => $item->recordId]),
            ProjectContentSection::RatingGroups => RatingGroupResource::getUrl('edit', ['record' => $item->recordId]),
            // Options are edited on their group's page.
            ProjectContentSection::RatingOptions => RatingGroupResource::getUrl('edit', ['record' => $item->parentId]),
        };
    }
}
