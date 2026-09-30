<?php

namespace App\Exceptions\Comments;

use DomainException;

final class CannotCommentException extends DomainException
{
    private const REASON_GUEST = 'guest';

    public function __construct(string $message = '', private readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    public static function becauseGuest(): self
    {
        return new self(__('ui.comments.errors.guest'), self::REASON_GUEST);
    }

    public function isGuest(): bool
    {
        return $this->reason === self::REASON_GUEST;
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.comments.errors.not_allowed'));
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self(__('ui.comments.errors.post_not_public'));
    }

    public static function becauseBodyIsInvalid(?string $message = null): self
    {
        return new self($message ?? __('ui.comments.errors.body_invalid'));
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
