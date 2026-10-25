<?php

namespace App\Enums;

/**
 * Why a scheduled write of a plan stopped short of done — the stable code an
 * action keeps in `failure_code` — and what it becomes:
 *
 * - held, until an administrator resumes it: its Persona Limited or
 *   Shadowbanned;
 * - cancelled for good: an administrator cancelled it, or stopped what was left
 *   of its plan, in the Action queue (`cancelled_by_administrator`); the
 *   Persona banned, deleted or no Persona at all, or
 *   what it publishes or votes on no longer usable — the plan, the photo, the
 *   category, an author's answer, the post a comment or a vote is for, the
 *   comment a reply answers or a vote is on, the rating option a vote
 *   chooses — or the action it depended on cancelled for good, which takes
 *   its whole branch with it (`parent_cancelled`);
 * - failed, for an administrator to decide: the approving administrator may
 *   no longer approve, the Persona's vote already there going the other way
 *   or choosing another option — never taken back or turned to make room
 *   (`vote_conflict`) — it was sent as often as it may be, or something
 *   unexpected;
 * - back to pending, later: its Persona's posting, commenting or voting limit
 *   reached for now — failed with the same code once sent back
 *   `max_deferrals` times — or sent before the action it depends on was
 *   done, waiting for it again (`parent_not_done`).
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
    case PostUnavailable = 'post_unavailable';
    case ReplyTargetUnavailable = 'reply_target_unavailable';
    case CommentUnavailable = 'comment_unavailable';
    case RatingOptionUnavailable = 'rating_option_unavailable';
    case VoteConflict = 'vote_conflict';
    case CancelledByAdministrator = 'cancelled_by_administrator';
    case ParentCancelled = 'parent_cancelled';
    case ParentNotDone = 'parent_not_done';
    case ApproverNotAllowed = 'approver_not_allowed';
    case AttemptsExhausted = 'attempts_exhausted';
    case RateLimited = 'rate_limited';
    case UnexpectedFailure = 'unexpected_failure';

    /** Why it stopped, as the Action queue and the Publication step say it. */
    public function description(): string
    {
        return match ($this) {
            self::AuthorRestricted => 'Its persona is limited or shadowbanned. Resume it once that is lifted.',
            self::AuthorBanned => 'Its persona is banned.',
            self::AuthorDeleted => 'Its persona’s account was deleted.',
            self::AuthorNotPersona => 'Its account is no longer a persona.',
            self::PlanUnavailable => 'The plan it belongs to is gone or was changed.',
            self::PhotoUnavailable => 'The photo can no longer be published.',
            self::CategoryUnavailable => 'The category is no longer active.',
            self::AnswerUnavailable => 'An author’s answer is no longer one to give.',
            self::PostUnavailable => 'The post is hidden, deleted or not published.',
            self::ReplyTargetUnavailable => 'The comment it answers is gone or hidden.',
            self::CommentUnavailable => 'The comment it votes on is gone or hidden.',
            self::RatingOptionUnavailable => 'The rating option, or its question, is no longer active.',
            self::VoteConflict => 'The persona already voted the other way, or chose another option. Nothing was taken back.',
            self::CancelledByAdministrator => 'Cancelled by an administrator.',
            self::ParentCancelled => 'What it depends on was cancelled.',
            self::ParentNotDone => 'It waits for what it depends on.',
            self::ApproverNotAllowed => 'The administrator who approved the plan may no longer publish it.',
            self::AttemptsExhausted => 'It was sent as many times as it may be, and lost each time.',
            self::RateLimited => 'The persona’s posting, commenting or voting limit held it back.',
            self::UnexpectedFailure => 'Something unexpected went wrong.',
        };
    }

    /** What an action stopped for this reason becomes. */
    public function status(): StudioActionStatus
    {
        return match ($this) {
            self::AuthorRestricted => StudioActionStatus::Held,
            self::AuthorBanned, self::AuthorDeleted, self::AuthorNotPersona, self::PlanUnavailable,
            self::PhotoUnavailable, self::CategoryUnavailable, self::AnswerUnavailable,
            self::PostUnavailable, self::ReplyTargetUnavailable, self::CommentUnavailable, self::RatingOptionUnavailable,
            self::CancelledByAdministrator, self::ParentCancelled => StudioActionStatus::Cancelled,
            self::ApproverNotAllowed, self::VoteConflict, self::AttemptsExhausted, self::UnexpectedFailure => StudioActionStatus::Failed,
            self::RateLimited, self::ParentNotDone => StudioActionStatus::Pending,
        };
    }
}
