<?php

namespace App\Exceptions\Votes;

use DomainException;

/**
 * Why a vote on a comment was not cast, said to the member in their language —
 * and, for a caller that acts on it rather than showing it, as a stable reason
 * (isRateLimited(), isCommentUnavailable(), …): never read from the message,
 * which is translated. A vote refused by the voting limit also says how soon
 * the next one is allowed (retryAfterSeconds()).
 */
final class CannotVoteCommentException extends DomainException
{
    private const REASON_GUEST = 'guest';

    private const REASON_NOT_ALLOWED = 'not_allowed';

    private const REASON_COMMENT_UNAVAILABLE = 'comment_unavailable';

    private const REASON_OWN_COMMENT = 'own_comment';

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
        return new self(__('ui.voting.errors.guest_comment'), self::REASON_GUEST);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.voting.errors.not_allowed_comment'), self::REASON_NOT_ALLOWED);
    }

    public function isNotAllowed(): bool
    {
        return $this->reason === self::REASON_NOT_ALLOWED;
    }

    /** The comment, or the post it is on, is hidden, deleted or no longer public. */
    public static function becauseCommentIsNotVisible(): self
    {
        return new self(__('ui.voting.errors.comment_not_visible'), self::REASON_COMMENT_UNAVAILABLE);
    }

    public function isCommentUnavailable(): bool
    {
        return $this->reason === self::REASON_COMMENT_UNAVAILABLE;
    }

    public static function becauseOwnComment(): self
    {
        return new self(__('ui.voting.errors.own_comment'), self::REASON_OWN_COMMENT);
    }

    public static function becauseRateLimited(string $message, ?int $retryAfterSeconds = null): self
    {
        return new self($message, self::REASON_RATE_LIMITED, $retryAfterSeconds);
    }

    public function isRateLimited(): bool
    {
        return $this->reason === self::REASON_RATE_LIMITED;
    }

    /** How many seconds until the voting limit allows the next vote; null when that is not why. */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
