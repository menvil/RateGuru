<?php

namespace App\Livewire\Theme;

use App\Actions\Users\UpdateThemePreferenceAction;
use App\Enums\ThemePreference;
use App\Models\User;
use App\Support\Theme\ThemeManager;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class ThemeSwitcher extends Component
{
    public string $preference = 'system';

    public string $layout = 'header';

    public function mount(): void
    {
        // What the layout applies: the person's own choice, or the project's
        // default theme when they made none — not 'system' regardless.
        $user = auth()->user();
        $this->preference = app(ThemeManager::class)->preferenceForUser($user instanceof User ? $user : null);
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

    /**
     * The page draws a switcher in more than one place — the user menu, the
     * guest header, the mobile menu — and each shows the theme any of them
     * chose.
     */
    #[On('theme-preference-changed')]
    public function followPreference(mixed $preference = null): void
    {
        $theme = is_string($preference) ? ThemePreference::tryFrom($preference) : null;

        if ($theme !== null) {
            $this->preference = $theme->value;
        }
    }

    public function render(): View
    {
        return view('livewire.theme.theme-switcher');
    }
}
