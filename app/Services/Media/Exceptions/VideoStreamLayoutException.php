<?php

namespace App\Services\Media\Exceptions;

final class VideoStreamLayoutException extends VideoIngestException
{
    public static function noVideoStream(): self
    {
        return new self('The file holds no video stream.');
    }
}
