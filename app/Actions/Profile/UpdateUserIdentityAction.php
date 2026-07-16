<?php

namespace App\Actions\Profile;

use App\Models\User;

final class UpdateUserIdentityAction
{
    /** @param array{name: string, username: string, email: string} $validated */
    public function execute(User $user, array $validated): void
    {
        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }
}
