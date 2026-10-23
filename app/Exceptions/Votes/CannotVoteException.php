<?php

namespace App\Exceptions\Votes;

use DomainException;

/**
 * Why a vote on a post was not cast, said to the member in their language —
 * and, for a caller that acts on it rather than showing it, as a stable reason
 * (isRateLimited(), isPostUnavailable(), …): never read from the message,
 * which is translated. A vote refused by the voting limit also says how soon
 * the next one is allowed (retryAfterSeconds()).
 */
final class CannotVoteException extends DomainException
{
    private const REASON_GUEST = 'guest';

    private const REASON_NOT_ALLOWED = 'not_allowed';

    private const REASON_POST_UNAVAILABLE = 'post_unavailable';

    private const REASON_OWN_POST = 'own_post';

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
        return new self(__('ui.voting.errors.guest_post'), self::REASON_GUEST);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.voting.errors.not_allowed_post'), self::REASON_NOT_ALLOWED);
    }

    public function isNotAllowed(): bool
    {
        return $this->reason === self::REASON_NOT_ALLOWED;
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self(__('ui.voting.errors.post_not_public'), self::REASON_POST_UNAVAILABLE);
    }

    public function isPostUnavailable(): bool
    {
        return $this->reason === self::REASON_POST_UNAVAILABLE;
    }

    public static function becauseOwnPost(): self
    {
        return new self(__('ui.voting.cannot_vote_own_post'), self::REASON_OWN_POST);
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
