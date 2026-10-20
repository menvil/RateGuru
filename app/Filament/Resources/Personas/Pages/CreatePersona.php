<?php

namespace App\Filament\Resources\Personas\Pages;

use App\Filament\Resources\Personas\PersonaResource;
use Filament\Resources\Pages\Page;

/**
 * Where a new Persona used to be made, kept so its address still leads
 * there: Personas, with the editor open in the drawer on a new Persona
 * (PersonaEditor). Nothing is drawn here.
 */
final class CreatePersona extends Page
{
    protected static string $resource = PersonaResource::class;

    protected string $view = 'filament.pages.personas';

    public function mount(): void
    {
        abort_unless(PersonaResource::canCreate(), 403);

        $this->redirect(ListPersonas::getUrl(['persona' => 'new']));
    }
}
