<?php

namespace App\Exceptions\Import;

use RuntimeException;

class ImportFetchException extends RuntimeException
{
    public static function requestFailed(string $url, int $status): self
    {
        return new self("Import request to '{$url}' failed with status {$status}.");
    }

    public static function connectionError(string $url, string $reason): self
    {
        return new self("Could not connect to '{$url}': {$reason}");
    }

    public static function responseTooLarge(string $url, int $maxBytes): self
    {
        $kb = (int) ($maxBytes / 1024);

        return new self("Response from '{$url}' exceeds the {$kb} KB limit.");
    }
}
