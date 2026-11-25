<?php

namespace App\Enums;

/**
 * What a media asset is, as opposed to what it is for (MediaKind): a still
 * image, a video, or an animation — an animated GIF kept as a silent,
 * looping video.
 */
enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Animation = 'animation';
}
