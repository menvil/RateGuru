<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Personas\Pages\EditPersona;
use App\Filament\Resources\Personas\PersonaResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * People in the admin: the list (ListUsers) and a person's own page
 * (EditUser), both drawn in Admin v2. The resource keeps the routes, the
 * navigation item, who may edit whom, and global search, whose results open
 * the person's page, or the list on them where the page is not theirs to
 * edit — and a Persona's editor in Content Studio for whoever may manage
 * Personas.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $recordTitleAttribute = 'username';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::MODERATION;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof User
            && auth()->user()?->can('manage', $record) === true;
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return match (true) {
            PersonaResource::canEdit($record) => EditPersona::getUrl(['record' => $record]),
            static::canEdit($record) => EditUser::getUrl(['record' => $record]),
            default => ListUsers::getUrl(['q' => $record->getAttribute('username')]),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
