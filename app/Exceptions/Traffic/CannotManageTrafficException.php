<?php

namespace App\Exceptions\Traffic;

use DomainException;

/**
 * Why a Traffic sources action wrote nothing. Each reason has its own
 * question, so a screen can say what happened and what to do next; the
 * message is written for the administrator who sees it.
 */
final class CannotManageTrafficException extends DomainException
{
    private const string REASON_NOT_ALLOWED = 'not_allowed';

    private const string REASON_GONE = 'gone';

    private const string REASON_STALE = 'stale';

    private const string REASON_REFUSED = 'refused';

    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('You can’t manage traffic sources any more, so nothing was saved.', self::REASON_NOT_ALLOWED);
    }

    public static function becauseItIsGone(string $what = 'This source'): self
    {
        return new self("{$what} no longer exists.", self::REASON_GONE);
    }

    public static function becauseItChanged(string $what = 'this source'): self
    {
        return new self("Someone saved {$what} after you opened it. Reload to see their changes.", self::REASON_STALE);
    }

    /** A rule refused the change; the message says which. */
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
