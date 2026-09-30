<?php

namespace App\Exceptions\Votes;

use DomainException;

final class CannotVoteException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self(__('ui.voting.errors.guest_post'));
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.voting.errors.not_allowed_post'));
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self(__('ui.voting.errors.post_not_public'));
    }

    public static function becauseOwnPost(): self
    {
        return new self(__('ui.voting.cannot_vote_own_post'));
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
