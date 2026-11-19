<?php

namespace App\Exceptions\Geo;

use App\Enums\GeoUpdateFailure;
use RuntimeException;

/** An update step that failed, with the reason the state file records. */
final class GeoUpdateException extends RuntimeException
{
    public function __construct(public readonly GeoUpdateFailure $failure, public readonly ?string $source = null)
    {
        parent::__construct($failure->value);
    }

    public static function because(GeoUpdateFailure $failure, ?string $source = null): self
    {
        return new self($failure, $source);
    }
}
