<?php

namespace App\Exceptions\Reports;

use DomainException;

final class CannotReopenReportException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to reopen reports.');
    }
}
