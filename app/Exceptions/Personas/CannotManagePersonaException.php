<?php

namespace App\Exceptions\Personas;

use DomainException;

/**
 * Why a Content Studio action wrote nothing to a Persona. Each reason has its
 * own question, so the editor can say what happened and what to do next.
 */
final class CannotManagePersonaException extends DomainException
{
    private const REASON_NOT_ALLOWED = 'not_allowed';

    private const REASON_GONE = 'gone';

    private const REASON_STALE = 'stale';

    private const REASON_USERNAME_TAKEN = 'username_taken';

    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to manage personas.', self::REASON_NOT_ALLOWED);
    }

    /** The account is deleted, or is not a Persona (any more). */
    public static function becausePersonaIsGone(): self
    {
        return new self('The account is no longer a persona that can be edited.', self::REASON_GONE);
    }

    public static function becausePersonaChanged(): self
    {
        return new self('The persona was changed since this edit started.', self::REASON_STALE);
    }

    public static function becauseUsernameIsTaken(): self
    {
        return new self('Another account already uses this username.', self::REASON_USERNAME_TAKEN);
    }

    /** Whether the account was deleted, or stopped being a Persona. */
    public function isGone(): bool
    {
        return $this->reason === self::REASON_GONE;
    }

    /** Whether somebody saved the Persona after the edit was drawn. */
    public function isStale(): bool
    {
        return $this->reason === self::REASON_STALE;
    }

    /** Whether the username is another account's. */
    public function isUsernameTaken(): bool
    {
        return $this->reason === self::REASON_USERNAME_TAKEN;
    }
}
