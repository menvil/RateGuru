<?php

namespace App\Filament\Pages;

use App\Actions\Settings\SaveProjectSettingsAction;
use App\Enums\SocialProvider;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\ProjectSettings;
use App\Queries\SocialProvidersInUseQuery;
use App\Services\Settings\ProjectPresetStatusService;
use App\Support\Auth\SocialProviderAvailability;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\TranslatableField;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/** @property Schema $form */
class ProjectSettingsPage extends Page
{
    protected string $view = 'filament.pages.project-settings';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::CONFIGURATION;

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    public static function getNavigationLabel(): string
    {
        return __('admin.project_settings.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.project_settings.title');
    }

    protected static ?string $slug = 'project-settings';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('manage-project-settings');
    }

    public function mount(): void
    {
        // What the project stores, as it stores it: a page or a language with
        // no text shows empty, not filled in from config. An installation
        // without a row shows the bootstrap it would be created from.
        $settings = ProjectSettings::find(1);
        $data = $settings ? $settings->toArray() : app(ProjectSettingsManager::class)->defaults();
        // Every provider is on until it is switched off; an unsaved row or
        // a provider added later must not render as an unticked box.
        $data['sign_in_providers'] = array_merge(
            array_fill_keys(SocialProvider::values(), true),
            array_intersect_key($data['sign_in_providers'] ?? [], array_flip(SocialProvider::values())),
        );

        $this->form->fill($data);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make(__('admin.project_settings.preset_status_title'))
                    ->description(__('admin.project_settings.preset_status_description'))
                    ->schema([
                        Placeholder::make('preset_status')
                            ->label(__('admin.project_settings.preset_status_label'))
                            ->content(fn (): string => $this->presetStatus()),
                    ]),

                Section::make(__('admin.project_settings.site_identity'))
                    ->schema([
                        TextInput::make('site_name')
                            ->label(__('admin.fields.site_name'))
                            ->required()
                            ->maxLength(120),
                        TextInput::make('site_tagline')
                            ->label(__('admin.fields.tagline'))
                            ->maxLength(180),
                        Textarea::make('site_description')
                            ->label(__('admin.fields.description'))
                            ->rows(3)
                            ->maxLength(2000),
                    ]),

                Section::make(__('admin.project_settings.object_labels'))
                    ->schema([
                        TextInput::make('object_singular_name')
                            ->label(__('admin.fields.singular_name'))
                            ->required()
                            ->maxLength(80),
                        TextInput::make('object_plural_name')
                            ->label(__('admin.fields.plural_name'))
                            ->required()
                            ->maxLength(80),
                        TextInput::make('upload_cta_label')
                            ->label(__('admin.fields.upload_cta_label'))
                            ->required()
                            ->maxLength(80),
                        TextInput::make('feed_title')
                            ->label(__('admin.fields.feed_title'))
                            ->required()
                            ->maxLength(120),
                    ]),

                Section::make(__('admin.project_settings.defaults'))
                    ->schema([
                        Select::make('default_theme')
                            ->label(__('admin.fields.default_theme'))
                            ->options([
                                'system' => __('admin.options.theme.system'),
                                'light' => __('admin.options.theme.light'),
                                'dark' => __('admin.options.theme.dark'),
                            ])
                            ->required()
                            ->rules(['in:system,light,dark']),
                        Select::make('default_sort')
                            ->label(__('admin.fields.default_sort'))
                            ->options([
                                'hot' => __('admin.options.sort.hot'),
                                'new' => __('admin.options.sort.new'),
                                'top' => __('admin.options.sort.top'),
                            ])
                            ->required()
                            ->rules(['in:hot,new,top']),
                    ]),

                Section::make(__('admin.project_settings.feature_flags'))
                    ->schema([
                        Toggle::make('feature_flags.show_comments')->label(__('admin.fields.show_comments')),
                        Toggle::make('feature_flags.show_share_buttons')->label(__('admin.fields.show_share_buttons')),
                        Toggle::make('feature_flags.show_vote_breakdown')->label(__('admin.fields.show_vote_breakdown')),
                        Toggle::make('feature_flags.show_follow_buttons')->label(__('admin.fields.show_follow_buttons')),
                        Toggle::make('feature_flags.post_detail_overlay_mode')->label(__('admin.fields.post_detail_overlay_mode')),
                        Toggle::make('feature_flags.show_saved_posts')->label(__('admin.fields.show_saved_posts')),
                        Toggle::make('feature_flags.allow_user_uploads')->label(__('admin.fields.allow_user_uploads')),
                        Toggle::make('feature_flags.allow_guest_viewing')->label(__('admin.fields.allow_guest_viewing')),
                    ]),

                Section::make(__('admin.project_settings.sign_in_title'))
                    ->description(__('admin.project_settings.sign_in_description'))
                    ->schema($this->signInProviderToggles()),

                Section::make('Translations')
                    ->schema([
                        Tabs::make('Translations')
                            ->tabs(array_map(
                                fn (string $locale, array $info) => Tabs\Tab::make($info['native'])
                                    ->schema([
                                        TextInput::make("site_name_translations.{$locale}")
                                            ->label(__('admin.fields.site_name'))
                                            ->maxLength(120),
                                        TextInput::make("site_tagline_translations.{$locale}")
                                            ->label(__('admin.fields.tagline'))
                                            ->maxLength(180),
                                        Textarea::make("site_description_translations.{$locale}")
                                            ->label(__('admin.fields.description'))
                                            ->rows(3)
                                            ->maxLength(2000),
                                        TextInput::make("object_singular_name_translations.{$locale}")
                                            ->label(__('admin.fields.singular_name'))
                                            ->maxLength(80),
                                        TextInput::make("object_plural_name_translations.{$locale}")
                                            ->label(__('admin.fields.plural_name'))
                                            ->maxLength(80),
                                        TextInput::make("upload_cta_label_translations.{$locale}")
                                            ->label(__('admin.fields.upload_cta_label'))
                                            ->maxLength(80),
                                        TextInput::make("feed_title_translations.{$locale}")
                                            ->label(__('admin.fields.feed_title'))
                                            ->maxLength(120),
                                    ]),
                                array_keys(config('locales.supported', [])),
                                config('locales.supported', [])
                            )),
                    ])
                    ->collapsible(),

                Section::make(__('admin.project_settings.static_pages'))
                    ->description(__('admin.project_settings.static_pages_description'))
                    ->schema([
                        Tabs::make('StaticPages')
                            ->tabs(array_map(
                                fn (string $locale, array $info) => Tabs\Tab::make($info['native'])
                                    ->schema($this->staticPageSections($locale)),
                                array_keys(config('locales.supported', [])),
                                config('locales.supported', []),
                            )),
                    ])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $validated = $this->form->getState();

        app(SaveProjectSettingsAction::class)->handle($validated);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    private function presetStatus(): string
    {
        return app(ProjectPresetStatusService::class)->display();
    }

    /** @return list<Toggle> */
    private function signInProviderToggles(): array
    {
        $availability = app(SocialProviderAvailability::class);
        $accounts = app(SocialProvidersInUseQuery::class)->counts();

        return array_map(
            function (SocialProvider $provider) use ($availability, $accounts): Toggle {
                $help = [__('admin.project_settings.sign_in_accounts', ['count' => $accounts[$provider->value] ?? 0])];

                if (! $availability->isConfigured($provider)) {
                    $prefix = strtoupper($provider->value);
                    $help[] = __('admin.project_settings.sign_in_not_configured', [
                        'keys' => "{$prefix}_CLIENT_ID, {$prefix}_CLIENT_SECRET",
                    ]);
                }

                return Toggle::make("sign_in_providers.{$provider->value}")
                    ->label(__('admin.fields.sign_in_provider', ['provider' => $provider->label()]))
                    ->helperText(implode(' ', $help));
            },
            SocialProvider::cases(),
        );
    }

    /** @return array<int, Section> */
    private function staticPageSections(string $locale): array
    {
        return array_map(
            fn (string $pageKey): Section => Section::make(__('admin.static_pages.'.$pageKey))
                ->schema([
                    // English is what every other language falls back to, and
                    // nothing falls back for English: it cannot be left blank.
                    TextInput::make("static_pages.{$pageKey}.{$locale}.title")
                        ->label(__('admin.fields.title'))
                        ->required($locale === TranslatableField::REFERENCE_LOCALE)
                        ->maxLength(160),
                    Textarea::make("static_pages.{$pageKey}.{$locale}.content")
                        ->label(__('admin.fields.content'))
                        ->required($locale === TranslatableField::REFERENCE_LOCALE)
                        ->rows(6)
                        ->maxLength(20000),
                ]),
            array_keys(config('static-pages.defaults', [])),
        );
    }
}
