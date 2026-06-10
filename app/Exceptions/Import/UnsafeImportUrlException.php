<?php

namespace App\Exceptions\Import;

use RuntimeException;

class UnsafeImportUrlException extends RuntimeException
{
    public static function invalidScheme(string $scheme): self
    {
        return new self("URL scheme '{$scheme}' is not allowed for import.");
    }

    public static function privateAddress(string $url): self
    {
        return new self('URL resolves to a private or reserved address and cannot be fetched.');
    }

    public static function invalidUrl(string $url): self
    {
        return new self("The provided URL is not valid: '{$url}'.");
    }
}
