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

    /**
     * Sign-ups from one network: an IPv4 address, or the /64 an IPv6 client
     * is handed whole and can change addresses within. Keyed with the app
     * key, because cache keys travel and a plain hash of an address is undone
     * by trying them all.
     */
    public static function registration(string $window, ?string $ip): string
    {
        return "rate-limit:registration-{$window}:network:".hash_hmac('sha256', self::network((string) $ip), (string) config('app.key'));
    }

    private static function network(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) === 4) {
            return $ip;
        }

        // An IPv4 client seen through an IPv6 socket is still that IPv4 client.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return (string) inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));
    }

    /**
     * A games visitor's action: a signed-in person by their account, an
     * anonymous browser by the hash of its games cookie (GameVisitor).
     */
    public static function gameVisitorAction(string $action, string $identityKey): string
    {
        return "rate-limit:{$action}:game-visitor:{$identityKey}";
    }
}
