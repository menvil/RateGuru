<?php

namespace App\Support\Posts;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Models\User;

/**
 * Whether a member's new post goes out at once or waits for a moderator.
 * Decided on the author's freshly locked row, when the post is created and
 * again when a video post's media is ready, so both follow one rule.
 */
final class PostPublicationRule
{
    /**
     * Published at once only for a trusted author who has also proved the
     * email address is theirs: every new account is trusted, so trust alone
     * would publish whatever an account registered with any address posts.
     * Staff — Admin and Moderator, never "anyone but a User" — are exempt from
     * the email half, since they could approve the post themselves. A
     * Persona's post always waits, whatever its trust or address: it is
     * published only by an administrator's approval, so a confirmed address
     * on its row — which it should never have — publishes nothing.
     */
    public function publishesAtOnce(User $lockedAuthor): bool
    {
        return ! $lockedAuthor->isPersona()
            && $lockedAuthor->trust_level >= MarkUserTrustedAction::TRUSTED_LEVEL
            && ($lockedAuthor->hasVerifiedEmail() || $lockedAuthor->isStaff());
    }
}
