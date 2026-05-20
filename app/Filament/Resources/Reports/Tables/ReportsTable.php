<?php

namespace App\Filament\Resources\Reports\Tables;

use Filament\Tables\Table;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([])
            ->filters([])
            ->recordActions([]);
    }
}
