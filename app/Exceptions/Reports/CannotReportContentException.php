<?php

namespace App\Exceptions\Reports;

use DomainException;

final class CannotReportContentException extends DomainException
{
    public static function becauseUnsupportedContent(): self
    {
        return new self('This content cannot be reported.');
    }
}
