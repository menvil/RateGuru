<?php

namespace App\Enums;

/** The badges a result profile can wear. */
enum GameProfileIcon: string
{
    case Shuffle = 'shuffle';
    case Compass = 'compass';
    case Eye = 'eye';
    case Sparkles = 'sparkles';
    case Award = 'award';
    case Target = 'target';
    case Flame = 'flame';
    case Crown = 'crown';
}
