<?php

namespace App\Livewire\Theme;

use App\Actions\Users\UpdateThemePreferenceAction;
use App\Enums\ThemePreference;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Component;

class ThemeSwitcher extends Component
{
    public string $preference = 'system';

    public string $layout = 'header';

    public function mount(): void
    {
        $user = auth()->user();
        $this->preference = $user instanceof User ? ($user->theme_preference ?? 'system') : 'system';
    }

    public function setThemePreference(string $preference): void
    {
        $theme = ThemePreference::tryFrom($preference);

        if ($theme === null) {
            $this->addError('preference', 'Invalid theme preference.');

            return;
        }

        $this->preference = $theme->value;
        $user = auth()->user();

        if ($user instanceof User) {
            app(UpdateThemePreferenceAction::class)->handle($user, $theme);
        }

        $this->dispatch('theme-preference-changed', preference: $theme->value);
    }

    public function render(): View
    {
        return view('livewire.theme.theme-switcher');
    }
}
