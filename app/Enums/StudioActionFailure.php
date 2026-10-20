<?php

namespace App\Enums;

/**
 * Why a scheduled write of a plan stopped short of done — the stable code an
 * action keeps in `failure_code` — and what it becomes:
 *
 * - held, until an administrator resumes it: its Persona Limited or
 *   Shadowbanned;
 * - cancelled for good: the Persona banned, deleted or no Persona at all, or
 *   what it publishes no longer usable — the plan, the photo, the category,
 *   an author's answer;
 * - failed, for an administrator to decide: the approving administrator may
 *   no longer approve, it was sent as often as it may be, or something
 *   unexpected;
 * - back to pending, later: its Persona's posting limit reached for now —
 *   and failed with the same code once sent back `max_deferrals` times.
 */
enum StudioActionFailure: string
{
    case AuthorRestricted = 'author_restricted';
    case AuthorBanned = 'author_banned';
    case AuthorDeleted = 'author_deleted';
    case AuthorNotPersona = 'author_not_persona';
    case PlanUnavailable = 'plan_unavailable';
    case PhotoUnavailable = 'photo_unavailable';
    case CategoryUnavailable = 'category_unavailable';
    case AnswerUnavailable = 'answer_unavailable';
    case ApproverNotAllowed = 'approver_not_allowed';
    case AttemptsExhausted = 'attempts_exhausted';
    case RateLimited = 'rate_limited';
    case UnexpectedFailure = 'unexpected_failure';

    /** What an action stopped for this reason becomes. */
    public function status(): StudioActionStatus
    {
        return match ($this) {
            self::AuthorRestricted => StudioActionStatus::Held,
            self::AuthorBanned, self::AuthorDeleted, self::AuthorNotPersona, self::PlanUnavailable,
            self::PhotoUnavailable, self::CategoryUnavailable, self::AnswerUnavailable => StudioActionStatus::Cancelled,
            self::ApproverNotAllowed, self::AttemptsExhausted, self::UnexpectedFailure => StudioActionStatus::Failed,
            self::RateLimited => StudioActionStatus::Pending,
        };
    }
}
