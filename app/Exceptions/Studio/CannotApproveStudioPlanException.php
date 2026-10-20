<?php

namespace App\Exceptions\Studio;

use App\Enums\StudioPlanApprovalProblem;
use App\Enums\StudioPlanApprovalRejection;
use DomainException;

/**
 * Why a publication plan was not approved, with nothing written: one typed
 * reason (`rejection`) and, when the plan itself is not ready, every problem
 * the approval preflight found in it (`problems`), the first deciding the
 * reason.
 */
final class CannotApproveStudioPlanException extends DomainException
{
    /**
     * @param  list<StudioPlanApprovalProblem>  $problems
     */
    private function __construct(
        public readonly StudioPlanApprovalRejection $rejection,
        public readonly array $problems = [],
        ?CannotManageStudioPlanException $previous = null,
    ) {
        parent::__construct($previous?->getMessage() ?? 'The publication plan is not ready to publish: '.implode(', ', array_map(
            fn (StudioPlanApprovalProblem $problem): string => $problem->value,
            $problems,
        )).'.', previous: $previous);
    }

    /** What a Content Studio write would refuse too: the administrator, the plan, its version, or its author's row. */
    public static function because(CannotManageStudioPlanException $refusal): self
    {
        return new self(match (true) {
            $refusal->isNotAllowed() => StudioPlanApprovalRejection::AccessDenied,
            $refusal->isGone() => StudioPlanApprovalRejection::PlanGone,
            $refusal->isNotDraft() => StudioPlanApprovalRejection::NotDraft,
            $refusal->isStale() => StudioPlanApprovalRejection::StaleVersion,
            default => StudioPlanApprovalRejection::AuthorUnavailable,
        }, previous: $refusal);
    }

    /**
     * What the approval preflight found.
     *
     * @param  non-empty-list<StudioPlanApprovalProblem>  $problems
     */
    public static function becauseOf(array $problems): self
    {
        return new self($problems[0]->rejection(), $problems);
    }
}
