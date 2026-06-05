<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\MarkUserTrustedAction;
use App\Actions\Moderation\ShadowbanUserAction;
use App\Actions\Moderation\UnbanUserAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                TextColumn::make('trust_level')
                    ->label('Trust')
                    ->numeric()
                    ->sortable(),
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
                Filter::make('active')
                    ->label('Active')
                    ->query(fn (Builder $query) => $query->where('status', UserStatus::Active)),
                Filter::make('banned')
                    ->label('Banned')
                    ->query(fn (Builder $query) => $query->where('status', UserStatus::Banned)),
                // Trusted is modelled via the `trust_level` column rather
                // than a dedicated UserStatus case; we treat
                // trust_level >= MarkUserTrustedAction::TRUSTED_LEVEL as
                // trusted to share a single source of truth with the
                // mark-trusted action and CreatePostAction.
                Filter::make('trusted')
                    ->label('Trusted')
                    ->query(fn (Builder $query) => $query
                        ->where('status', UserStatus::Active)
                        ->where('trust_level', '>=', MarkUserTrustedAction::TRUSTED_LEVEL)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (User $record): bool => auth()->user()?->can('manage', $record) === true),
                Action::make('ban')
                    ->label('Ban')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record): bool => auth()->user()?->can('ban', $record) === true
                        && $record->status !== UserStatus::Banned
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (User $record, array $data): void {
                        app(BanUserAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('unban')
                    ->label('Unban')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (User $record): bool => auth()->user()?->can('unban', $record) === true
                        && in_array($record->status, [UserStatus::Banned, UserStatus::Shadowbanned], true)
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (User $record, array $data): void {
                        app(UnbanUserAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('markTrusted')
                    ->label('Mark trusted')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->visible(fn (User $record): bool => auth()->user()?->can('markTrusted', $record) === true
                        && $record->status === UserStatus::Active
                        && (int) $record->trust_level < MarkUserTrustedAction::TRUSTED_LEVEL
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (User $record, array $data): void {
                        app(MarkUserTrustedAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('shadowban')
                    ->label('Shadowban')
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->visible(fn (User $record): bool => auth()->user()?->can('shadowban', $record) === true
                        && $record->status !== UserStatus::Shadowbanned
                        && $record->status !== UserStatus::Banned
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (User $record, array $data): void {
                        app(ShadowbanUserAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
            ]);
    }
}
