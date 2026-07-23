<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(80)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, ?string $state, Get $get, Set $set): void {
                        if ($operation === 'create' && blank($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(100)
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->unique(ignoreRecord: true)
                    ->helperText('Stable URL identifier. Lowercase letters, numbers, and hyphens.'),
                TextInput::make('sort_order')
                    ->label('Sort order')
                    ->integer()
                    ->required()
                    ->minValue(0)
                    ->default(10),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Section::make('Translations')
                    ->schema([
                        Tabs::make('Translations')
                            ->tabs(array_map(
                                fn (string $locale, array $info) => Tabs\Tab::make($info['native'])
                                    ->schema([
                                        TextInput::make("name_translations.{$locale}")
                                            ->label('Name')
                                            ->maxLength(80),
                                    ]),
                                array_keys(config('locales.supported', [])),
                                config('locales.supported', []),
                            )),
                    ])
                    ->collapsible(),
            ]);
    }
}
