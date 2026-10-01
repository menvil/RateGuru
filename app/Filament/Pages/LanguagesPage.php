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
use App\Support\Translations\MissingTranslationReason;
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
 * it is — the place a language is enabled, disabled or made the default.
 *
 * Three separate facts per language:
 *
 *  - installed: shipped by the release (config/locales.php + lang/{code}/);
 *  - complete: the application catalogs, held to the same contract CI holds
 *    them to, and this project's own content as the database holds it now;
 *  - enabled: whether visitors are offered it.
 *
 * The page only reads; every change goes through
 * UpdateProjectLocaleSettingsAction, which also refuses to enable a language
 * whose application catalogs break the contract. One whose project content is missing
 * translations can, after an explicit warning: visitors then see fallback
 * text where a translation is missing, which an administrator may accept.
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
                    ->description(fn (array $record): ?string => $record['default'] ? 'Default' : null),
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
                $this->setDefaultAction(),
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
            ->requiresConfirmation(fn (array $record): bool => $record['project_missing'] > 0)
            ->modalHeading(fn (array $record): string => "Enable {$record['label']}?")
            ->modalDescription(fn (array $record): string => "{$record['label']} has {$record['project_missing']} missing project translations. Visitors may see fallback content.\n\nEnable anyway?")
            ->modalSubmitActionLabel('Enable anyway')
            ->action(function (array $record): void {
                $locales = app(LocaleManager::class);

                try {
                    $this->updateLocales([...$locales->enabledCodes(), $record['code']], $locales->projectDefault());
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
            ->visible(fn (array $record): bool => $record['enabled'])
            ->disabled(fn (array $record): bool => $this->disableRefusal($record) !== null)
            ->tooltip(fn (array $record): ?string => $this->disableRefusal($record))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => "Disable {$record['label']}?")
            ->modalDescription('Visitors who chose it get another language. Their choice is kept and applies again if the language is enabled again.')
            ->action(function (array $record): void {
                $refusal = $this->disableRefusal($this->languages()[$record['code']]);

                if ($refusal !== null) {
                    $this->refuse($refusal);

                    return;
                }

                $locales = app(LocaleManager::class);
                $this->updateLocales(array_values(array_diff($locales->enabledCodes(), [$record['code']])), $locales->projectDefault());

                Notification::make()->title("{$record['label']} disabled")->success()->send();
            });
    }

    private function setDefaultAction(): Action
    {
        return Action::make('setDefault')
            ->label('Set as default')
            ->icon('heroicon-o-star')
            ->visible(fn (array $record): bool => $record['enabled'] && ! $record['default'])
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => "Make {$record['label']} the default?")
            ->modalDescription('New visitors whose browser asks for no offered language get the default.')
            ->action(function (array $record): void {
                $locales = app(LocaleManager::class);

                if (! $locales->isEnabled($record['code'])) {
                    $this->refuse('Only an enabled language can be the default. Enable it first.');

                    return;
                }

                $this->updateLocales($locales->enabledCodes(), $record['code']);

                Notification::make()->title("{$record['label']} is now the default")->success()->send();
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
     * Why a language cannot be disabled, or null when it can. Choosing a new
     * default is left to the administrator, never made for them.
     *
     * @param  array<string, mixed>  $record
     */
    private function disableRefusal(array $record): ?string
    {
        if ($record['default']) {
            return 'Set another default language first.';
        }

        if (count(app(LocaleManager::class)->enabledCodes()) <= 1) {
            return 'At least one language has to stay enabled.';
        }

        return null;
    }

    /** @param  list<string>  $enabled */
    private function updateLocales(array $enabled, string $default): void
    {
        app(UpdateProjectLocaleSettingsAction::class)->handle($enabled, $default);
        $this->rows = null;
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
        $default = $locales->projectDefault();
        $rows = [];

        foreach ($locales->supported() as $code => $info) {
            $rows[$code] = [
                'code' => $code,
                'flag' => $info['flag'],
                'label' => $info['label'],
                'native' => $info['native'],
                'enabled' => $locales->isEnabled($code),
                'default' => $code === $default,
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
     * The missing translations by section, each with why it counts as
     * missing and a link to the editor that already manages that content.
     *
     * @return list<array{label: string, items: list<array{label: string, field: string, reason: string, reason_color: string, explanation: string|null, url: string}>}>
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
                    'reason' => $item->reason->label(),
                    'reason_color' => $item->reason === MissingTranslationReason::Untranslated ? 'gray' : 'warning',
                    'explanation' => $item->reason->explanation(),
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
