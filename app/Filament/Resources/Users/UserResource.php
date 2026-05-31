<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $recordTitleAttribute = 'username';

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            TextInput::make('username')
                ->label('Username')
                ->maxLength(255),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('role')
                ->label('Role')
                ->options([
                    UserRole::User->value => 'User',
                    UserRole::Moderator->value => 'Moderator',
                    UserRole::Admin->value => 'Admin',
                ])
                ->required(),
            Select::make('status')
                ->label('Status')
                ->options([
                    UserStatus::Active->value => 'Active',
                    UserStatus::Limited->value => 'Limited',
                    UserStatus::Banned->value => 'Banned',
                    UserStatus::Shadowbanned->value => 'Shadowbanned',
                ])
                ->required(),
            TextInput::make('password')
                ->label('New password')
                ->password()
                ->revealable()
                ->formatStateUsing(fn (): ?string => null)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                ->minLength(8)
                ->maxLength(255)
                ->helperText('Leave blank to keep the current password.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
