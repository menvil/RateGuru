<?php

namespace App\Exceptions\Studio;

use App\Enums\StudioDiscussionGenerationError;
use DomainException;

/**
 * Why no discussion generation was asked for: writing discussions is not
 * configured; the photo has no finished analysis or the post no final title
 * to write from; no Persona, or one that cannot take part, was chosen; or
 * the comment to write again is not part of the plan's discussion at the
 * level asked for. What the plan itself refuses — the administrator may not,
 * the plan is gone, past its Draft or changed meanwhile — is
 * CannotManageStudioPlanException.
 */
final class CannotRequestStudioDiscussionGenerationException extends DomainException
{
    public function __construct(public readonly StudioDiscussionGenerationError $error)
    {
        parent::__construct($error->message());
    }
}
