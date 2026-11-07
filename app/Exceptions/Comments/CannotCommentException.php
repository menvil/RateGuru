<?php

namespace App\Exceptions\Comments;

use DomainException;

/**
 * Why a comment was not written, said to the member in their language — and,
 * for a caller that acts on it rather than showing it, as a stable reason
 * (isRateLimited(), isPostUnavailable(), …): never read from the message,
 * which is translated. A comment refused by the commenting limit also says
 * how soon the next one is allowed (retryAfterSeconds()).
 */
final class CannotCommentException extends DomainException
{
    private const REASON_GUEST = 'guest';

    private const REASON_NOT_ALLOWED = 'not_allowed';

    private const REASON_POST_UNAVAILABLE = 'post_unavailable';

    private const REASON_REPLY_TARGET_UNAVAILABLE = 'reply_target_unavailable';

    private const REASON_BODY_INVALID = 'body_invalid';

    private const REASON_RATE_LIMITED = 'rate_limited';

    public function __construct(
        string $message = '',
        private readonly ?string $reason = null,
        private readonly ?int $retryAfterSeconds = null,
    ) {
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
        return new self(__('ui.comments.errors.not_allowed'), self::REASON_NOT_ALLOWED);
    }

    public function isNotAllowed(): bool
    {
        return $this->reason === self::REASON_NOT_ALLOWED;
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self(__('ui.comments.errors.post_not_public'), self::REASON_POST_UNAVAILABLE);
    }

    public function isPostUnavailable(): bool
    {
        return $this->reason === self::REASON_POST_UNAVAILABLE;
    }

    /** The comment a reply answers is gone, hidden, a reply itself or another post's. */
    public static function becauseReplyTargetUnavailable(): self
    {
        return new self(__('ui.comments.errors.reply_target_unavailable'), self::REASON_REPLY_TARGET_UNAVAILABLE);
    }

    public function isReplyTargetUnavailable(): bool
    {
        return $this->reason === self::REASON_REPLY_TARGET_UNAVAILABLE;
    }

    public static function becauseBodyIsInvalid(?string $message = null): self
    {
        return new self($message ?? __('ui.comments.errors.body_invalid'), self::REASON_BODY_INVALID);
    }

    public static function becauseRateLimited(string $message, ?int $retryAfterSeconds = null): self
    {
        return new self($message, self::REASON_RATE_LIMITED, $retryAfterSeconds);
    }

    public function isRateLimited(): bool
    {
        return $this->reason === self::REASON_RATE_LIMITED;
    }

    /** How many seconds until the commenting limit allows the next comment; null when that is not why. */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
