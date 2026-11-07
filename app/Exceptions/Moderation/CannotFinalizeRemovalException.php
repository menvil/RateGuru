<?php

namespace App\Exceptions\Moderation;

use DomainException;

final class CannotFinalizeRemovalException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to finalize moderation removals.');
    }

    public static function becauseTargetStateIsInvalid(): self
    {
        return new self('Target is not in a finalizable moderation state.');
    }

    public static function becauseReasonIsRequired(): self
    {
        return new self('Finalizing a moderation removal requires an internal reason.');
    }
}
