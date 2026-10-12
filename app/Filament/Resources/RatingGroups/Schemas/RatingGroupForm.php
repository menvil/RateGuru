<?php

namespace App\Filament\Resources\RatingGroups\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RatingGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->alphaDash()
                    ->maxLength(120)
                    ->unique(ignoreRecord: true),
                TextInput::make('label')
                    ->required()
                    ->maxLength(120),
                Textarea::make('description')
                    ->rows(3)
                    ->maxLength(1000),
                TextInput::make('min_options')
                    ->label('Minimum active options')
                    ->integer()
                    ->required()
                    ->minValue(2)
                    ->maxValue(10)
                    ->lte('max_options')
                    ->default(2),
                TextInput::make('max_options')
                    ->label('Maximum active options')
                    ->integer()
                    ->required()
                    ->minValue(2)
                    ->maxValue(10)
                    ->gte('min_options')
                    ->default(10),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                TextInput::make('sort_order')
                    ->label('Sort order')
                    ->integer()
                    ->required()
                    ->minValue(0)
                    ->default(0),
            ]);
    }
}
