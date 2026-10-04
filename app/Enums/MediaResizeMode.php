<?php

namespace App\Enums;

enum MediaResizeMode: string
{
    case Contain = 'contain';
    case CoverSquare = 'cover_square';
    case Cover = 'cover';
}
