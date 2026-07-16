<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fixtures;

use App\Models\User;

final class DirectPermissionCheckController
{
    public function __invoke(User $user): bool
    {
        return $user->isAdmin();
    }
}

namespace App\Policies\Fixtures;

use App\Models\User;

final class DirectPermissionCheckPolicy
{
    public function view(User $user): bool
    {
        return $user->isAdmin();
    }
}
