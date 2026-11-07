<?php

namespace App\Enums;

/**
 * What an account may administer (see docs/architecture/user-lifecycle.md).
 *
 * A Persona is a managed account that takes part in the community like a
 * User — it posts, comments, votes and follows under the same lifecycle
 * rules — but it administers nothing and never signs in for itself
 * (User::canSignIn()). It is not staff.
 */
enum UserRole: string
{
    case User = 'user';
    case Persona = 'persona';
    case Moderator = 'moderator';
    case Admin = 'admin';

    /**
     * The roles that administer the community. The one definition of staff:
     * everything that exempts or distinguishes staff asks this, never "not
     * a User", which would quietly count a Persona in.
     */
    public function isStaff(): bool
    {
        return in_array($this, [
            self::Admin,
            self::Moderator,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Persona => 'Persona',
            self::Moderator => 'Moderator',
            self::Admin => 'Admin',
        };
    }
}
