<?php

namespace App\Enums;

/**
 * Whether a picture or video is safe for work, by the line the project drew
 * (docs/architecture/content-rating.md): NSFW is exposed female breasts or
 * nipples, genitals, sexual activity and sex toys; everything else — lingerie,
 * swimwear, bare buttocks, see-through clothing — is SFW. Unknown until a
 * classifier or a moderator says. A doubtful case is NSFW: showing too much in
 * an SFW mode is worse than hiding too much.
 */
enum ContentRating: string
{
    case Unknown = 'unknown';
    case Sfw = 'sfw';
    case Nsfw = 'nsfw';
}
