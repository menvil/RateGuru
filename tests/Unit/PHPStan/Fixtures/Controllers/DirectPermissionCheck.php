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

namespace App\Http\Requests\Fixtures;

use App\Models\User;

final class DirectPermissionCheckRequest
{
    public function authorize(User $user): bool
    {
        return $user->canCreateContent();
    }
}

namespace App\Livewire\Fixtures;

use App\Models\User;

final class DirectPermissionCheckComponent
{
    public function canModerate(User $user): bool
    {
        return $user->isModerator();
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
