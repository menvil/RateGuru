<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * yt-dlp could not say what a page holds — it failed, or answered nothing
 * readable — or it found a carousel with a video among its photos. Neither
 * is "this page has no video": a page whose probe failed is never posted as
 * its picture.
 */
final class YtDlpProbeException extends RuntimeException
{
    private function __construct(string $message, public readonly bool $carouselWithVideo)
    {
        parent::__construct($message);
    }

    public static function failed(string $extractor): self
    {
        return new self("yt-dlp could not read a {$extractor} page.", false);
    }

    public static function carouselWithVideo(string $extractor): self
    {
        return new self("A {$extractor} carousel holds a video; one of several is not taken.", true);
    }
}
