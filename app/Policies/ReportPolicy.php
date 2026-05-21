<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function resolve(User $user, Report $report): bool
    {
        return $this->canProcess($user);
    }

    public function ignore(User $user, Report $report): bool
    {
        return $this->canProcess($user);
    }

    private function canProcess(User $user): bool
    {
        return $user->isModerator() || $user->isAdmin();
    }
}
