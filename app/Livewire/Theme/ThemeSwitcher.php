<?php

namespace App\Livewire\Theme;

use App\Enums\ThemePreference;
use Illuminate\View\View;
use Livewire\Attributes\Prop;
use Livewire\Component;

class ThemeSwitcher extends Component
{
    public string $preference = 'system';

    #[Prop]
    public string $layout = 'header';

    public function mount(): void
    {
        $user = auth()->user();
        $this->preference = $user?->theme_preference ?? 'system';
    }

    public function setThemePreference(string $preference): void
    {
        if (! ThemePreference::isValid($preference)) {
            $this->addError('preference', 'Invalid theme preference.');

            return;
        }

        $this->preference = $preference;

        if (auth()->check()) {
            auth()->user()->update(['theme_preference' => $preference]);
        }

        $this->dispatch('theme-preference-changed', preference: $preference);
    }

    public function render(): View
    {
        return view('livewire.theme.theme-switcher');
    }
}
