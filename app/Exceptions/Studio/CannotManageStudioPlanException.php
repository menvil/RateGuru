<?php

namespace App\Exceptions\Studio;

use DomainException;

/**
 * Why a Content Studio action wrote nothing to a publication plan. Each reason
 * has its own question, so the editor can say what happened and what to do
 * next.
 */
final class CannotManageStudioPlanException extends DomainException
{
    private const REASON_NOT_ALLOWED = 'not_allowed';

    private const REASON_GONE = 'gone';

    private const REASON_NOT_DRAFT = 'not_draft';

    private const REASON_STALE = 'stale';

    private const REASON_AUTHOR_UNAVAILABLE = 'author_unavailable';

    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to manage publication plans.', self::REASON_NOT_ALLOWED);
    }

    public static function becausePlanIsGone(): self
    {
        return new self('The publication plan no longer exists.', self::REASON_GONE);
    }

    public static function becausePlanIsNotDraft(): self
    {
        return new self('Only a draft publication plan can be changed or deleted.', self::REASON_NOT_DRAFT);
    }

    public static function becausePlanChanged(): self
    {
        return new self('The draft was changed by another administrator since this edit started.', self::REASON_STALE);
    }

    public static function becauseAuthorIsUnavailable(): self
    {
        return new self('The chosen author is no longer a persona that can be chosen.', self::REASON_AUTHOR_UNAVAILABLE);
    }

    /** Whether the plan was deleted. */
    public function isGone(): bool
    {
        return $this->reason === self::REASON_GONE;
    }

    /** Whether the plan has moved past the Draft. */
    public function isNotDraft(): bool
    {
        return $this->reason === self::REASON_NOT_DRAFT;
    }

    /** Whether somebody saved the plan after the edit was drawn. */
    public function isStale(): bool
    {
        return $this->reason === self::REASON_STALE;
    }

    /** Whether the chosen author was deleted, or stopped being a Persona. */
    public function isAuthorUnavailable(): bool
    {
        return $this->reason === self::REASON_AUTHOR_UNAVAILABLE;
    }
}
