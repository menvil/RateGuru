<?php

namespace App\Enums;

/**
 * Where a Full Result step invites an anonymous visitor to create an account:
 * on the result itself, on a separate Registration step after it, or nowhere.
 */
enum GameSignupMode: string
{
    case Inline = 'inline';
    case Step = 'step';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Inline => 'On the result card',
            self::Step => 'On a separate Registration step',
            self::None => 'No sign-up',
        };
    }
}
