<?php

namespace App\Services\Media\Exceptions;

final class VideoUnreadableException extends VideoIngestException
{
    public static function probeFailed(): self
    {
        return new self('The file could not be read as a video in a supported format.');
    }

    public static function transcodeFailed(): self
    {
        return new self('The video could not be transcoded.');
    }

    public static function noDuration(): self
    {
        return new self('The video does not say how long it plays.');
    }
}
