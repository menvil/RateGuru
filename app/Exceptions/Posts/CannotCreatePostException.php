<?php

namespace App\Exceptions\Posts;

use DomainException;

/**
 * Why no post was created. Each reason has its own question, so a caller
 * that has to tell them apart — Content Studio's executor — need not read
 * the message.
 */
final class CannotCreatePostException extends DomainException
{
    private const REASON_NOT_ALLOWED = 'not_allowed';

    private const REASON_CATEGORY = 'category';

    private const REASON_AUTHOR_ANSWER = 'author_answer';

    private const REASON_IMAGE = 'image';

    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to create posts.', self::REASON_NOT_ALLOWED);
    }

    public static function becauseCategoryIsInvalid(): self
    {
        return new self('The selected category is not available.', self::REASON_CATEGORY);
    }

    public static function becauseAuthorAnswerIsInvalid(): self
    {
        return new self('One of the selected author answers is not available.', self::REASON_AUTHOR_ANSWER);
    }

    public static function becauseImageIsUnavailable(): self
    {
        return new self('The stored image is not a ready post image no post uses.', self::REASON_IMAGE);
    }

    public function isNotAllowed(): bool
    {
        return $this->reason === self::REASON_NOT_ALLOWED;
    }

    public function isCategoryInvalid(): bool
    {
        return $this->reason === self::REASON_CATEGORY;
    }

    public function isAuthorAnswerInvalid(): bool
    {
        return $this->reason === self::REASON_AUTHOR_ANSWER;
    }

    public function isImageUnavailable(): bool
    {
        return $this->reason === self::REASON_IMAGE;
    }
}
