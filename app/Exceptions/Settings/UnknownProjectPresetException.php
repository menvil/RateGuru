<?php

namespace App\Exceptions\Settings;

use InvalidArgumentException;

class UnknownProjectPresetException extends InvalidArgumentException
{
    public static function for(string $key): self
    {
        return new self("Unknown project preset: [{$key}].");
    }
}
