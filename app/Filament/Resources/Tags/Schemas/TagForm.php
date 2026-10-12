<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Models\Tag;
use App\Rules\UniqueEffectiveTagSlug;
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
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    // Validate uniqueness against the *effective* slug — the
                    // value actually saved, including the name-derived fallback
                    // when the field is left blank. A plain ->unique() would
                    // only check the raw (possibly empty) field value and let a
                    // colliding generated slug reach the DB unique index.
                    ->rule(fn (Get $get, ?Tag $record): UniqueEffectiveTagSlug => new UniqueEffectiveTagSlug(
                        $get('name'),
                        $record?->getKey(),
                    ))
                    ->helperText('Lowercase, URL-safe. Auto-generated from the name if left blank.'),
            ]);
    }
}
