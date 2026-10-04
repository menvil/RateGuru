<?php

namespace App\Exceptions\Settings;

use RuntimeException;

class ProjectPresetHasContentException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Project content already exists. Use --force only after reviewing the destructive preset changes.');
    }
}
