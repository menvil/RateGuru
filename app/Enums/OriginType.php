<?php

namespace App\Enums;

enum OriginType: string
{
    case Homemade = 'homemade';
    case Restaurant = 'restaurant';
    case Unknown = 'unknown';
}
