<?php

namespace App\Filament\Resources\Tags\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TagForm
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
                        // Auto-fill the slug from the name only while creating;
                        // editing must never silently rewrite an existing slug.
                        if ($operation !== 'create') {
                            return;
                        }

                        // Only auto-fill when the slug is still empty so a slug
                        // the user has already edited is never clobbered by a
                        // later change to the name.
                        if (filled($get('slug'))) {
                            return;
                        }

                        $set('slug', Str::slug((string) $state));
                    }),
                TextInput::make('slug')
                    ->label('Slug')
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->helperText('Lowercase, URL-safe. Auto-generated from the name if left blank.'),
            ]);
    }
}
