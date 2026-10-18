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

namespace App\Filament\Fixtures;

use App\Models\User;

final class DirectLifecycleCheckPage
{
    public function showFollowButton(User $viewer): bool
    {
        return $viewer->canFollow();
    }

    public function showDeleteButton(User $viewer): bool
    {
        return $viewer->canManageContent();
    }
}

namespace App\Actions\Fixtures;

use App\Models\User;

// Non-presentation layer: the same lifecycle capability calls must NOT be
// flagged here — actions and domain code are exactly where they belong.
final class DirectLifecycleCheckAction
{
    public function handle(User $actor): bool
    {
        return $actor->canFollow() && $actor->canManageContent();
    }
}

namespace App\Http\Controllers\Fixtures;

use App\Models\User;

final class DirectSignInCheckController
{
    public function __invoke(User $user): bool
    {
        return $user->canSignIn();
    }
}

namespace App\Filament\Fixtures;

use App\Enums\UserRole;
use App\Models\User;

final class DirectRoleCheckPage
{
    public function showPersonaNotice(User $person): bool
    {
        return $person->isPersona();
    }

    public function showStaffBadge(User $person): bool
    {
        return $person->isStaff();
    }

    // The role's own vocabulary, read for a badge, is not an authorization
    // decision: only the account's isStaff() is flagged.
    public function staffBadge(UserRole $role): bool
    {
        return $role->isStaff();
    }
}

namespace App\Actions\Fixtures;

use App\Models\User;

final class DirectRoleCheckAction
{
    public function handle(User $actor): bool
    {
        return $actor->canSignIn() && $actor->isStaff() && ! $actor->isPersona();
    }
}

namespace App\Livewire\Fixtures;

use App\Enums\UserRole;
use App\Models\User;

final class NullsafePermissionCheckComponent
{
    public function showPublishButton(?User $user): bool
    {
        return $user?->canCreateContent() ?? false;
    }

    public function showStaffBadge(?User $person): bool
    {
        return $person?->isStaff() ?? false;
    }

    // A role that may be missing is still the role's own vocabulary.
    public function staffBadge(?UserRole $role): bool
    {
        return $role?->isStaff() ?? false;
    }
}
