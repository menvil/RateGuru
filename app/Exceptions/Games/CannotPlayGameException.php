<?php

namespace App\Exceptions\Games;

use DomainException;

/**
 * Why a visitor's play went no further. Each reason has its own question, so
 * the game page can say what happened in the visitor's language.
 */
final class CannotPlayGameException extends DomainException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseGameIsNotLive(): self
    {
        return new self('The game is not live.', 'not_live');
    }

    public static function becauseNothingCanBePlayed(): self
    {
        return new self('No version of the game can be played right now.', 'unavailable');
    }

    public static function becauseAttemptIsOver(): self
    {
        return new self('The attempt cannot go on.', 'over');
    }

    public static function becauseEmailIsRequired(): self
    {
        return new self('An email is required to go on.', 'email_required');
    }

    public static function becauseEmailIsInvalid(): self
    {
        return new self('The email address is not valid.', 'email_invalid');
    }
}
