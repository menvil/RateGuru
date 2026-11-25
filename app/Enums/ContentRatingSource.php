<?php

namespace App\Enums;

/** Who set an asset's content rating: the classifiers, or a moderator — whose word is final. */
enum ContentRatingSource: string
{
    case Auto = 'auto';
    case Moderator = 'moderator';
}
