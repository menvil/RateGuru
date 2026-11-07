<?php

namespace App\Exceptions\Reports;

use DomainException;

final class CannotResolveReportException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to resolve reports.');
    }
}
