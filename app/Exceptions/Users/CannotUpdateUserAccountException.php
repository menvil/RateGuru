<?php

namespace App\Exceptions\Users;

use DomainException;

final class CannotUpdateUserAccountException extends DomainException
{
    private const REASON_PERSONA = 'persona';

    private const REASON_CHANGED = 'changed';

    private const REASON_TAKEN = 'taken';

    private function __construct(string $message, private readonly ?string $reason = null, public readonly ?string $field = null)
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

    public static function becauseAccountChangedMeanwhile(): self
    {
        return new self('The account was changed since the editor read it.', self::REASON_CHANGED);
    }

    /** Another account took the username or email address between the editor's check and the save. */
    public static function becauseValueIsTaken(string $field): self
    {
        return new self("Another account took this {$field} meanwhile.", self::REASON_TAKEN, $field);
    }

    /** Whether the edit was refused because the account is, or would become, a Persona. */
    public function isAboutPersona(): bool
    {
        return $this->reason === self::REASON_PERSONA;
    }

    /** Whether the edit was refused because the account changed since the editor read it. */
    public function isAboutChangeMeanwhile(): bool
    {
        return $this->reason === self::REASON_CHANGED;
    }

    /** Whether the edit was refused because another account took one of its unique values; `field` says which. */
    public function isAboutTakenValue(): bool
    {
        return $this->reason === self::REASON_TAKEN;
    }
}
