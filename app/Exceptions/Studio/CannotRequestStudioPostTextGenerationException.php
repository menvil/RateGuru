<?php

namespace App\Exceptions\Studio;

use App\Enums\StudioPostTextGenerationError;
use DomainException;

/**
 * Why no batch of post text suggestions was asked for: writing texts is not
 * configured, the plan's photo has no finished analysis to write from, or
 * its photo or its author is no longer the one the editor showed. What the
 * plan itself refuses — the administrator may not, the plan is gone or past
 * its Draft — is CannotManageStudioPlanException.
 */
final class CannotRequestStudioPostTextGenerationException extends DomainException
{
    private function __construct(public readonly StudioPostTextGenerationError $error)
    {
        parent::__construct($error->message());
    }

    public static function becauseNotConfigured(): self
    {
        return new self(StudioPostTextGenerationError::NotConfigured);
    }

    public static function becauseAnalysisIsMissing(): self
    {
        return new self(StudioPostTextGenerationError::AnalysisMissing);
    }

    public static function becauseImageChanged(): self
    {
        return new self(StudioPostTextGenerationError::StaleAnalysis);
    }

    public static function becauseAuthorChanged(): self
    {
        return new self(StudioPostTextGenerationError::AuthorChanged);
    }
}
