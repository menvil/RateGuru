<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class UpdatePasswordAction
{
    public function execute(User $user, string $password): void
    {
        $user->update([
            'password' => Hash::make($password),
        ]);
    }
}
