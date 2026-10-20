<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\Page;

/**
 * A person's address, kept so links to it still lead there — from Posts,
 * Comments, Reports, global search or a bookmark: Users, with their editor
 * open in the drawer (UserEditor). Nothing is drawn here; someone the viewer
 * may not edit is refused, as before.
 */
final class EditUser extends Page
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.pages.users';

    public function mount(int|string $record): void
    {
        $person = is_numeric($record) ? User::query()->find((int) $record) : null;

        abort_if($person === null, 404);
        abort_unless(UserResource::canEdit($person), 403);

        $this->redirect(ListUsers::getUrl(['user' => $person->id]));
    }
}
