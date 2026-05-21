<?php

namespace App\Exceptions\Tags;

use DomainException;

final class CannotDeleteTagException extends DomainException
{
    public const REASON_NOT_ALLOWED = 'not_allowed';

    public const REASON_USED_BY_POSTS = 'used_by_posts';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to delete tags.', self::REASON_NOT_ALLOWED);
    }

    public static function becauseTagIsUsedByPosts(): self
    {
        return new self('Tag is attached to posts and cannot be deleted.', self::REASON_USED_BY_POSTS);
    }
}
