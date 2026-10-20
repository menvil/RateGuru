<?php

namespace App\Exceptions\Moderation;

use DomainException;

/**
 * Why a moderation of a post wrote nothing: the moderator may not, or the post
 * is not in the state the moderation needs. Each reason has its own question,
 * so a caller that has to tell them apart need not read the message.
 */
final class CannotModeratePostException extends DomainException
{
    private const REASON_NOT_ALLOWED = 'not_allowed';

    private const REASON_STATUS = 'status';

    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.moderation.errors.not_allowed'), self::REASON_NOT_ALLOWED);
    }

    public static function becausePostStatusIsInvalid(): self
    {
        return new self(__('ui.moderation.errors.invalid_status'), self::REASON_STATUS);
    }

    public function isNotAllowed(): bool
    {
        return $this->reason === self::REASON_NOT_ALLOWED;
    }
}
