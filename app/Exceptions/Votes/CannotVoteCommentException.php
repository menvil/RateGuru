<?php

namespace App\Exceptions\Votes;

use DomainException;

final class CannotVoteCommentException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self(__('ui.voting.errors.guest_comment'));
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.voting.errors.not_allowed_comment'));
    }

    public static function becauseCommentIsNotVisible(): self
    {
        return new self(__('ui.voting.errors.comment_not_visible'));
    }

    public static function becauseOwnComment(): self
    {
        return new self(__('ui.voting.errors.own_comment'));
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
