<?php

namespace App\Exceptions\Studio;

use App\Enums\StudioDiscussionGenerationError;
use DomainException;

/**
 * Why a discussion generation's proposal was not taken into the plan: it is
 * not the plan's, has no proposal, was taken in already, or was written from
 * a post, photo, discussion or Persona the plan no longer has. What the plan
 * itself refuses — the administrator may not, the plan is gone, past its
 * Draft or changed meanwhile — is CannotManageStudioPlanException, and what
 * the discussion's own rules refuse is a ValidationException.
 */
final class CannotApplyStudioDiscussionGenerationException extends DomainException
{
    public function __construct(public readonly StudioDiscussionGenerationError $error)
    {
        parent::__construct($error->message());
    }
}
