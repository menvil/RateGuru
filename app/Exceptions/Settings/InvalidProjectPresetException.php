<?php

namespace App\Exceptions\Settings;

use InvalidArgumentException;

class InvalidProjectPresetException extends InvalidArgumentException
{
    public static function unknownDefaultLocale(string $key, string $locale): self
    {
        return new self("Project preset [{$key}] sets the default locale [{$locale}], which is not installed.");
    }

    public static function defaultLocaleNotOfferedByDefault(string $key, string $locale): self
    {
        return new self("Project preset [{$key}] sets the default locale [{$locale}], which a new project does not offer (it is not enabled_by_default).");
    }
}
