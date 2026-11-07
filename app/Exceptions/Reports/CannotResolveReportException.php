<?php

namespace App\Exceptions\Reports;

use DomainException;

final class CannotResolveReportException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to resolve reports.');
    }

    /** Someone else closed it first — or reopened and closed it — so this changes nothing. */
    public static function becauseItIsNoLongerOpen(): self
    {
        return new self('The report is no longer open.');
    }
}
