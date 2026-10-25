<?php

namespace App\Exceptions\Studio;

use App\Enums\StudioActionStatus;
use DomainException;

/**
 * Why an administrator's Retry, Resume, Cancel or Stop in the Action queue —
 * or a switch of the executor — wrote nothing. Each reason has its own code,
 * so the screen says what happened rather than a generic error.
 */
final class CannotManageStudioActionException extends DomainException
{
    private function __construct(string $message, public readonly string $reason, public readonly ?StudioActionStatus $status = null)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to manage Content Studio actions.', 'not_allowed');
    }

    public static function becauseActionIsGone(): self
    {
        return new self('The studio action no longer exists.', 'gone');
    }

    public static function becausePlanIsGone(): self
    {
        return new self('The publication plan no longer exists.', 'plan_gone');
    }

    /** It is not in the state the operation is for — done, running, cancelled, or not failed or held. */
    public static function becauseOfItsStatus(StudioActionStatus $status): self
    {
        return new self("The studio action is {$status->value}.", 'status', $status);
    }

    /** What it depends on is not done, and never will be or not yet: it would only wait, or be cancelled with it. */
    public static function becauseParentIsNotDone(): self
    {
        return new self('The action this one depends on is not done.', 'parent_not_done');
    }

    /** Only an approved plan, or one running, has actions left to stop. */
    public static function becausePlanIsNotRunning(): self
    {
        return new self('The publication plan is not approved or running.', 'plan_not_running');
    }

    public function isNotAllowed(): bool
    {
        return $this->reason === 'not_allowed';
    }

    public function isGone(): bool
    {
        return $this->reason === 'gone' || $this->reason === 'plan_gone';
    }
}
