<?php

namespace App\Filament\Resources\RatingGroups\RelationManagers;

use App\Models\RatingGroup;
use App\Models\RatingOption;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class OptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'options';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->alphaDash()
                    ->maxLength(120)
                    ->rule(fn (?RatingOption $record) => Rule::unique('rating_options', 'key')
                        ->where('rating_group_id', $this->getOwnerRecord()->getKey())
                        ->ignore($record?->getKey())),
                TextInput::make('label')
                    ->required()
                    ->maxLength(120),
                Textarea::make('description')
                    ->rows(3)
                    ->maxLength(1000),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->rule(fn (?RatingOption $record): Closure => $this->activeStateRule($record)),
                TextInput::make('sort_order')
                    ->label('Sort order')
                    ->integer()
                    ->required()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('archived_at')
                    ->label('Archived at')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (?RatingOption $record): bool => $record?->archived_at !== null),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('votes'))
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Sort')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('votes_count')
                    ->label('Votes')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('archived_at')
                    ->label('Archived')
                    ->dateTime()
                    ->placeholder('-'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->createAnother(false),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    private function activeStateRule(?RatingOption $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            /** @var RatingGroup $group */
            $group = $this->getOwnerRecord();
            $activeCount = $group->options()->active()->count();
            $willBeActive = filter_var($value, FILTER_VALIDATE_BOOL);
            $isCurrentlyActive = $record?->is_active ?? false;

            if ($willBeActive && ! $isCurrentlyActive && $activeCount >= $group->max_options) {
                $fail("This group cannot have more than {$group->max_options} active options.");

                return;
            }

            if (! $willBeActive && $isCurrentlyActive && $activeCount <= $group->min_options) {
                $fail("This group must keep at least {$group->min_options} active options.");
            }
        };
    }
}
