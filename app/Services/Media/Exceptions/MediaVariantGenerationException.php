<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;
use Throwable;

final class MediaVariantGenerationException extends RuntimeException
{
    public static function couldNotDecodeMaster(?Throwable $previous = null): self
    {
        return new self('Could not decode the master image bytes to generate a variant.', previous: $previous);
    }

    public static function couldNotResample(?Throwable $previous = null): self
    {
        return new self('Could not resample the master image while generating a variant.', previous: $previous);
    }

    public static function couldNotEncode(string $mimeType, ?Throwable $previous = null): self
    {
        return new self("Could not encode the generated variant as [{$mimeType}].", previous: $previous);
    }

    public static function unexpectedOutputFormat(string $expected, string $actual): self
    {
        return new self("Expected the generated variant to be [{$expected}], got [{$actual}].");
    }

    public static function unexpectedOutputDimensions(int $expectedWidth, int $expectedHeight, int $actualWidth, int $actualHeight): self
    {
        return new self("Expected the generated variant to be {$expectedWidth}x{$expectedHeight}, got {$actualWidth}x{$actualHeight}.");
    }
}
