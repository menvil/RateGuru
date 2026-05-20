<?php

namespace App\Filament\Resources\Comments\Tables;

use Filament\Tables\Table;

class CommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([]);
    }
}
