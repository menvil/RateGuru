<?php

namespace App\Enums;

enum PostImageContext: string
{
    case Feed = 'feed';
    case Drawer = 'drawer';
    case Standalone = 'standalone';
    case Fullscreen = 'fullscreen';
}
