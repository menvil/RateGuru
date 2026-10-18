<?php

namespace App\Exceptions\Users;

use DomainException;

final class CannotUpdateUserAccountException extends DomainException
{
    private const REASON_PERSONA = 'persona';

    private function __construct(string $message, private readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to edit this account.');
    }

    public static function becauseAccountIsPersona(): self
    {
        return new self('A persona is not edited as a user account.', self::REASON_PERSONA);
    }

    /** Whether the edit was refused because the account is, or would become, a Persona. */
    public function isAboutPersona(): bool
    {
        return $this->reason === self::REASON_PERSONA;
    }
}
