<?php

namespace App\Exceptions\Observability;

use InvalidArgumentException;

final class InvalidLogEventNameException extends InvalidArgumentException
{
    public static function for(string $name): self
    {
        return new self("Invalid log event name: '{$name}'. Must be lowercase dot-separated (e.g. posts.created).");
    }
}
