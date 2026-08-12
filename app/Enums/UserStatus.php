<?php

namespace App\Enums;

/**
 * User lifecycle status — the single source of truth for what each
 * lifecycle state may do in the product (see docs/architecture/user-lifecycle.md).
 *
 * Lifecycle status is deliberately independent from UserRole: status answers
 * "is this account in good standing", role answers "what is this account
 * allowed to administer". Privileged-panel access requires BOTH a lifecycle-
 * eligible status (canAccessPrivilegedPanel) and an allowed role.
 *
 * Capability methods below are business permission decisions. State-transition
 * guards (e.g. "cannot ban an already banned user") intentionally keep direct
 * enum comparisons in the moderation actions — they reason about the state
 * itself, not about what the state may do.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Limited = 'limited';
    case Banned = 'banned';
    case Shadowbanned = 'shadowbanned';

    public function canCreateContent(): bool
    {
        return $this === self::Active;
    }

    public function canComment(): bool
    {
        return $this === self::Active;
    }

    public function canVote(): bool
    {
        return $this === self::Active;
    }

    public function canReport(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether a user in this state may start following other authors.
     * Existing follows are never removed by a lifecycle change.
     */
    public function canFollow(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether an author in this state may gain new followers. Distinct from
     * canFollow(): this is a property of the follow target, not the actor.
     */
    public function canBeFollowed(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether a user in this state may perform content-management actions in
     * the product UI (currently: deleting posts from the feed). Ownership and
     * role decide which posts may be targeted; lifecycle decides whether the
     * actor is in good standing to act at all.
     */
    public function canManageContent(): bool
    {
        return $this === self::Active;
    }

    /**
     * Profile mutation is currently not lifecycle-restricted anywhere in the
     * product: banned, limited and shadowbanned users may still edit their
     * profile. This method pins that audited behavior in one place so a
     * future PR can tighten it deliberately rather than accidentally.
     */
    public function canUpdateProfile(): bool
    {
        return true;
    }

    /**
     * Login is currently not lifecycle-restricted: no auth code path inspects
     * status, so banned/limited/shadowbanned users can authenticate and
     * browse (participation is blocked by the capabilities above). Declared
     * here unenforced so a future auth-enforcement PR has a single flip point.
     */
    public function canAuthenticate(): bool
    {
        return true;
    }

    /**
     * Lifecycle half of admin-panel access: only an account in good standing
     * is eligible for privileged access. Role (admin/moderator) is the other,
     * independent half — see User::canAccessPanel().
     */
    public function canAccessPrivilegedPanel(): bool
    {
        return $this === self::Active;
    }
}
