<?php

namespace App\Filament\Pages;

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Exceptions\Settings\UnknownProjectPresetException;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\ProjectSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class ProjectSettingsPage extends Page
{

    protected string $view = 'filament.pages.project-settings';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::SYSTEM;

    protected static ?string $navigationLabel = 'Project Settings';

    protected static ?string $title = 'Project Settings';

    protected static ?string $slug = 'project-settings';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $settings = ProjectSettings::find(1);

        $this->form->fill($settings ? $settings->toArray() : []);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Site Identity')
                    ->schema([
                        TextInput::make('site_name')
                            ->label('Site name')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('site_tagline')
                            ->label('Tagline')
                            ->maxLength(180),
                        Textarea::make('site_description')
                            ->label('Description')
                            ->rows(3)
                            ->maxLength(2000),
                    ]),

                Section::make('Object Labels')
                    ->schema([
                        TextInput::make('object_singular_name')
                            ->label('Singular name')
                            ->required()
                            ->maxLength(80),
                        TextInput::make('object_plural_name')
                            ->label('Plural name')
                            ->required()
                            ->maxLength(80),
                        TextInput::make('upload_cta_label')
                            ->label('Upload CTA label')
                            ->required()
                            ->maxLength(80),
                        TextInput::make('feed_title')
                            ->label('Feed title')
                            ->required()
                            ->maxLength(120),
                    ]),

                Section::make('Defaults')
                    ->schema([
                        TextInput::make('default_locale')
                            ->label('Default locale')
                            ->required()
                            ->maxLength(12),
                        Select::make('default_theme')
                            ->label('Default theme')
                            ->options([
                                'system' => 'System',
                                'light' => 'Light',
                                'dark' => 'Dark',
                            ])
                            ->required()
                            ->rules(['in:system,light,dark']),
                        Select::make('default_sort')
                            ->label('Default sort')
                            ->options([
                                'hot' => 'Hot',
                                'new' => 'New',
                                'top' => 'Top',
                            ])
                            ->required()
                            ->rules(['in:hot,new,top']),
                    ]),

                Section::make('Feature Flags')
                    ->schema([
                        Toggle::make('feature_flags.show_comments')->label('Show comments'),
                        Toggle::make('feature_flags.show_share_buttons')->label('Show share buttons'),
                        Toggle::make('feature_flags.show_vote_breakdown')->label('Show vote breakdown'),
                        Toggle::make('feature_flags.show_follow_buttons')->label('Show follow buttons'),
                        Toggle::make('feature_flags.show_saved_posts')->label('Show saved posts'),
                        Toggle::make('feature_flags.allow_user_uploads')->label('Allow user uploads'),
                        Toggle::make('feature_flags.allow_guest_viewing')->label('Allow guest viewing'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $validated = $this->form->getState();

        ProjectSettings::updateOrCreate(
            ['id' => 1],
            $validated
        );

        app(\App\Support\Settings\ProjectSettingsManager::class)->flush();

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    public function applyPreset(string $presetKey): void
    {
        try {
            app(ApplyProjectPresetAction::class)->handle($presetKey);
        } catch (UnknownProjectPresetException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $settings = ProjectSettings::find(1);
        $this->form->fill($settings ? $settings->toArray() : []);

        Notification::make()
            ->title("Preset '{$presetKey}' applied")
            ->success()
            ->send();
    }

    public static function presetOptions(): array
    {
        return collect(config('project_presets', []))
            ->mapWithKeys(fn (array $preset, string $key): array => [$key => $preset['label']])
            ->all();
    }
}
