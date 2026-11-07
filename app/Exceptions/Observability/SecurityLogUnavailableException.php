<?php

namespace App\Exceptions\Observability;

use RuntimeException;

/**
 * The security log could not take a line. Built from the failure's class and
 * the first line of its message alone, never with the failure as its previous
 * exception: that one's trace and the rest of its message hold the record
 * being written, the visitor's address included.
 */
final class SecurityLogUnavailableException extends RuntimeException
{
    public static function because(string $failure): self
    {
        return new self("The security log could not be written: {$failure}");
    }
}
