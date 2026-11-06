<?php

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Auth;

final class LogoutUserAction
{
    public function execute(): void
    {
        Auth::guard('web')->logout();
    }
}
