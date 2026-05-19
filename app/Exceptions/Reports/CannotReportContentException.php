<?php

namespace App\Exceptions\Reports;

use DomainException;

final class CannotReportContentException extends DomainException
{
    public static function becauseUnsupportedContent(): self
    {
        return new self('This content cannot be reported.');
    }

    public static function becauseGuest(): self
    {
        return new self('Guests cannot report content.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to report content.');
    }

    public static function becauseDuplicateReport(): self
    {
        return new self('You have already reported this content.');
    }
}
