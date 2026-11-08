<?php

namespace App\Support\Games\Versus;

/**
 * Whether a picture can be shown to players: stored with its web sizes, still
 * being processed, or not available at all (none, deleted, or failed).
 */
enum ImageState: string
{
    case Ready = 'ready';
    case Processing = 'processing';
    case Unavailable = 'unavailable';
}
