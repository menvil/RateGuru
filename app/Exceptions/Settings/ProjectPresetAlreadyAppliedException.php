<?php

namespace App\Exceptions\Settings;

use RuntimeException;

class ProjectPresetAlreadyAppliedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('A project preset has already been applied. Use --force to replace it deliberately.');
    }
}
