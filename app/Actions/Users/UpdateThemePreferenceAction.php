<?php

namespace App\Actions\Users;

use App\Enums\ThemePreference;
use App\Models\User;

final class UpdateThemePreferenceAction
{
    public function handle(User $user, ThemePreference $preference): void
    {
        $user->update(['theme_preference' => $preference->value]);
    }
}
