<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Personas\Pages\ListPersonas;
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
 * People in the admin: the list (ListUsers), drawn in Admin v2, with a
 * person's editor in the drawer over it (UserEditor); EditUser keeps a
 * person's old address leading there. The resource keeps the routes, the
 * navigation item, who may edit whom, and global search, whose results open
 * the person's editor, or the list on them where they are not the viewer's
 * to edit — and a Persona's editor in Content Studio for whoever may manage
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
            PersonaResource::canEdit($record) => ListPersonas::getUrl(['persona' => $record->getKey()]),
            static::canEdit($record) => ListUsers::getUrl(['user' => $record->getKey()]),
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
