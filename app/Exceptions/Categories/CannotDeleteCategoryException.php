<?php

namespace App\Exceptions\Categories;

use DomainException;

final class CannotDeleteCategoryException extends DomainException
{
    public const REASON_NOT_ALLOWED = 'not_allowed';

    public const REASON_USED_BY_POSTS = 'used_by_posts';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to delete categories.', self::REASON_NOT_ALLOWED);
    }

    public static function becauseCategoryIsUsedByPosts(): self
    {
        return new self('Category is assigned to posts and cannot be deleted.', self::REASON_USED_BY_POSTS);
    }
}
