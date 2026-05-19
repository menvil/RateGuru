<?php

namespace App\Actions\Moderation;

use App\Models\User;

final class BanUserAction
{
    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
