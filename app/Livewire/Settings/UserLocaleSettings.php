<?php

namespace App\Livewire\Settings;

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
        $this->locale = auth()->user()?->locale ?? app()->getLocale();
    }

    public function save(LocaleManager $manager): void
    {
        $this->validate([
            'locale' => ['required', 'string', Rule::in(array_keys($manager->supported()))],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $user->update(['locale' => $this->locale]);
    }

    public function render(): View
    {
        return view('livewire.settings.user-locale-settings', [
            'supported' => app(LocaleManager::class)->supported(),
        ]);
    }
}
