<?php

namespace App\Exceptions\Studio;

use App\Enums\StudioImageAnalysisError;
use DomainException;

/**
 * Why no analysis of a plan's photo was asked for: analysis is not
 * configured, the plan has no saved photo, or its photo is no longer the one
 * the editor showed. What the plan itself refuses — the administrator may
 * not, the plan is gone or past its Draft — is CannotManageStudioPlanException.
 */
final class CannotRequestStudioImageAnalysisException extends DomainException
{
    private function __construct(public readonly StudioImageAnalysisError $error)
    {
        parent::__construct($error->message());
    }

    public static function becauseNotConfigured(): self
    {
        return new self(StudioImageAnalysisError::NotConfigured);
    }

    public static function becausePlanHasNoImage(): self
    {
        return new self(StudioImageAnalysisError::ImageMissing);
    }

    public static function becauseImageChanged(): self
    {
        return new self(StudioImageAnalysisError::StaleImage);
    }
}
