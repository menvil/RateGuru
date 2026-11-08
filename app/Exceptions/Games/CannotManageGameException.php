<?php

namespace App\Exceptions\Games;

use DomainException;

/**
 * Why a game action wrote nothing. Each reason has its own question, so an
 * editor can say what happened and what to do next; the message is written
 * for the administrator who sees it.
 */
final class CannotManageGameException extends DomainException
{
    private const REASON_NOT_ALLOWED = 'not_allowed';

    private const REASON_GONE = 'gone';

    private const REASON_STALE = 'stale';

    private const REASON_REFUSED = 'refused';

    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('You can’t manage games any more, so nothing was saved.', self::REASON_NOT_ALLOWED);
    }

    public static function becauseItIsGone(string $what = 'This game'): self
    {
        return new self("{$what} no longer exists.", self::REASON_GONE);
    }

    public static function becauseItChanged(string $what = 'this game'): self
    {
        return new self("Someone saved {$what} after you opened it. Reload to see their changes.", self::REASON_STALE);
    }

    /** A rule of the game refused the change; the message says which. */
    public static function because(string $message): self
    {
        return new self($message, self::REASON_REFUSED);
    }

    public function isNotAllowed(): bool
    {
        return $this->reason === self::REASON_NOT_ALLOWED;
    }

    public function isGone(): bool
    {
        return $this->reason === self::REASON_GONE;
    }

    public function isStale(): bool
    {
        return $this->reason === self::REASON_STALE;
    }
}
