<?php

namespace App\Filament\Resources\RatingGroups\Tables;

use App\Models\RatingGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RatingGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['options', 'votes']))
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('options_count')
                    ->label('Options')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('votes_count')
                    ->label('Votes')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('option_range')
                    ->label('Active range')
                    ->state(fn (RatingGroup $record): string => "{$record->min_options}-{$record->max_options}"),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Sort')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
