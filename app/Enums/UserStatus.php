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

    /**
     * Irreversible tombstone: the account was deleted and anonymized, the
     * row remains only so community contribution (posts, comments, votes,
     * reports) keeps a valid author reference. Every capability fails
     * closed and no moderation transition may ever leave this state.
     */
    case Deleted = 'deleted';

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
     * Public profile/identity mutation is Active-only (PR-F): a sanctioned
     * account keeps its existing identity and avatar visible but may not
     * change them. The separate password-security flow and account
     * self-deletion are deliberately NOT gated by this capability.
     */
    public function canUpdateProfile(): bool
    {
        return $this === self::Active;
    }

    /**
     * Living sanctions never lock a user out of their account: limited,
     * banned and shadowbanned users may authenticate, browse and manage
     * account security — participation is blocked by the capabilities
     * above. Enforced at the auth boundary (AuthenticateUserAction). A
     * Deleted tombstone can never authenticate again — additionally
     * guaranteed by anonymization (scrambled email, random password,
     * cleared tokens).
     */
    public function canAuthenticate(): bool
    {
        return $this !== self::Deleted;
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
