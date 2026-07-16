<?php

namespace App\Actions\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class DeleteUserAccountAction
{
    public function execute(User $user): void
    {
        Auth::guard('web')->logout();
        $user->delete();
    }
}
