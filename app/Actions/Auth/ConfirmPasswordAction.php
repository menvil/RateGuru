<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class ConfirmPasswordAction
{
    public function execute(User $user, #[SensitiveParameter] string $password): void
    {
        if (Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => $password,
        ])) {
            return;
        }

        throw ValidationException::withMessages([
            'password' => __('auth.password'),
        ]);
    }
}
