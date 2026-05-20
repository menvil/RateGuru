<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('posts'))
            ->columns([
                TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->sortable()
                    ->color(fn (UserRole $state): string => match ($state) {
                        UserRole::Admin => 'danger',
                        UserRole::Moderator => 'warning',
                        UserRole::User => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Limited => 'warning',
                        UserStatus::Banned => 'danger',
                        UserStatus::Shadowbanned => 'gray',
                    }),
                TextColumn::make('posts_count')
                    ->label('Posts')
                    ->numeric()
                    ->sortable(),
                // TODO: replace with a real user-level report aggregate once
                // user reports are introduced. For now, this is a static
                // placeholder to reserve table real estate and signal intent.
                TextColumn::make('reports_count_placeholder')
                    ->label('Reports')
                    ->state(fn (User $record): string => '—')
                    ->tooltip('User-level report aggregation is not implemented yet.'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                //
            ]);
    }
}
