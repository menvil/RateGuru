<?php

namespace App\Livewire\Settings;

use App\Actions\Users\UpdateUserLocaleAction;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class UserLocaleSettings extends Component
{
    public string $locale = '';

    public function mount(): void
    {
        $user = auth()->user();
        // A stored language the project no longer offers is kept on the
        // account but not preselected: the choice shown is one that can be saved.
        $this->locale = ($user instanceof User ? $user->preferredLocale() : null) ?? app()->getLocale();
    }

    public function save(): void
    {
        $this->validate([
            'locale' => ['required', 'string', Rule::in(app(LocaleManager::class)->enabledCodes())],
        ]);

        /** @var User $user */
        $user = auth()->user();
        app(UpdateUserLocaleAction::class)->handle($user, $this->locale);

        // The page around this form was rendered in the old language; a full
        // load renders all of it in the new one, as the header switcher does.
        $this->redirect(route('profile.edit'));
    }

    public function render(): View
    {
        return view('livewire.settings.user-locale-settings', [
            'enabled' => app(LocaleManager::class)->enabled(),
        ]);
    }
}
