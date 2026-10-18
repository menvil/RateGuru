<?php

namespace App\Filament\Resources\Personas;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Personas\Pages\CreatePersona;
use App\Filament\Resources\Personas\Pages\EditPersona;
use App\Filament\Resources\Personas\Pages\ListPersonas;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Content Studio's Personas: the list (ListPersonas), a new Persona
 * (CreatePersona) and one Persona's editor (EditPersona), drawn in Admin v2.
 * A Persona is an ordinary account with the Persona role, so the resource is
 * over User, narrowed to that role; there is no second kind of account.
 *
 * Only an active administrator gets here (`manage-personas`): the navigation
 * item, every page and every Livewire request on them ask, and the Content
 * Studio actions ask again on the locked rows. A moderator reaches a Persona
 * only through Users, for the moderation they already do there.
 *
 * Global search stays with Users, which leads an administrator from a
 * Persona's result to its editor here.
 */
class PersonaResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'personas';

    protected static ?string $modelLabel = 'persona';

    protected static ?string $pluralModelLabel = 'personas';

    protected static ?string $navigationLabel = 'Personas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 10;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::CONTENT_STUDIO;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-personas') === true;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    /** A living Persona, for whoever may manage Personas; never a deleted account, never anyone else. */
    public static function canEdit(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof User
            && $record->role === UserRole::Persona
            && $record->status !== UserStatus::Deleted;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', UserRole::Persona);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPersonas::route('/'),
            'create' => CreatePersona::route('/create'),
            'edit' => EditPersona::route('/{record}/edit'),
        ];
    }
}
