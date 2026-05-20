<?php

namespace App\Filament\Resources\Comments\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('body')
                    ->label('Comment')
                    ->limit(80)
                    ->wrap()
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([]);
    }
}
