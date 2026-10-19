<?php

namespace App\Filament\Resources\Personas\Pages;

use App\Filament\Resources\Personas\PersonaResource;
use App\Models\User;
use Filament\Resources\Pages\Page;

/**
 * A Persona's address, kept so links to it still lead there — from Users,
 * global search or a bookmark: Personas, with its editor open in the drawer
 * (PersonaEditor). Nothing is drawn here; a record that is no living Persona
 * is not found.
 */
final class EditPersona extends Page
{
    protected static string $resource = PersonaResource::class;

    protected string $view = 'filament.pages.personas';

    public function mount(int|string $record): void
    {
        $persona = is_numeric($record) ? User::query()->find((int) $record) : null;

        abort_unless(PersonaResource::canAccess(), 403);
        abort_unless($persona !== null && PersonaResource::canEdit($persona), 404);

        $this->redirect(ListPersonas::getUrl(['persona' => $persona->id]));
    }
}
