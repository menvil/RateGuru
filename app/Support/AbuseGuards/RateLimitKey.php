<?php

namespace App\Support\AbuseGuards;

use App\Models\User;

final class RateLimitKey
{
    public static function userAction(string $action, User $user): string
    {
        return "rate-limit:{$action}:user:{$user->id}";
    }

    public static function userTarget(
        string $action,
        User $user,
        string $targetType,
        int|string $targetId,
    ): string {
        return "rate-limit:{$action}:user:{$user->id}:target:{$targetType}:{$targetId}";
    }
}
