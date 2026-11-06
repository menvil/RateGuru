<?php

namespace App\Actions\Auth;

use App\Data\Auth\PendingSocialLink;
use App\Data\Auth\SocialIdentity;
use Illuminate\Contracts\Session\Session;

/**
 * Parks a social identity whose email already belongs to an existing account
 * until that account's owner signs in and claims it. Server-side session
 * only; one slot, so a newer collision replaces an older one.
 */
final class StorePendingSocialLinkAction
{
    public function execute(SocialIdentity $identity, Session $session): void
    {
        $session->put(
            PendingSocialLink::SESSION_KEY,
            PendingSocialLink::fromIdentity($identity, now())->toSession(),
        );
    }
}
