<?php

namespace App\Exceptions\Rating;

use DomainException;

final class CannotVoteForRatingOptionException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self(__('ui.voting.errors.guest_rating'));
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.voting.errors.not_allowed_rating'));
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self(__('ui.voting.errors.rating_post_not_public'));
    }

    public static function becauseOwnPost(): self
    {
        return new self(__('ui.voting.cannot_vote_own_post'));
    }

    public static function becauseOptionIsInactive(): self
    {
        return new self(__('ui.voting.errors.option_inactive'));
    }

    public static function becauseGroupIsInactive(): self
    {
        return new self(__('ui.voting.errors.group_inactive'));
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
