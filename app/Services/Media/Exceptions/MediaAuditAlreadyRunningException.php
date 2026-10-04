<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

final class MediaAuditAlreadyRunningException extends RuntimeException
{
    public static function make(): self
    {
        return new self('A full media audit is already running.');
    }
}
