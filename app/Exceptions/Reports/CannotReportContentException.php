<?php

namespace App\Exceptions\Reports;

use DomainException;

final class CannotReportContentException extends DomainException
{
    public static function becauseUnsupportedContent(): self
    {
        return new self(__('ui.report.errors.unsupported'));
    }

    public static function becauseGuest(): self
    {
        return new self(__('ui.report.errors.guest'));
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.report.errors.not_allowed'));
    }

    public static function becauseDuplicateReport(): self
    {
        return new self(__('ui.report.errors.duplicate'));
    }

    public static function becauseContentIsNotReportable(): self
    {
        return new self(__('ui.report.errors.not_reportable'));
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
