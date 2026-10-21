<?php

namespace App\Enums;

/**
 * Why a publication plan was not approved: what the administrator, the plan
 * or its version is (access_denied, plan_gone, not_draft, stale_version), or
 * which part of the plan the approval preflight found not ready to publish —
 * each preflight problem belongs to one of these (StudioPlanApprovalProblem):
 * a prepared discussion that cannot be published as it is adds up to
 * invalid_discussion.
 */
enum StudioPlanApprovalRejection: string
{
    case AccessDenied = 'access_denied';
    case PlanGone = 'plan_gone';
    case NotDraft = 'not_draft';
    case StaleVersion = 'stale_version';
    case AuthorUnavailable = 'author_unavailable';
    case IncompletePlan = 'incomplete_plan';
    case InvalidMedia = 'invalid_media';
    case InvalidTaxonomy = 'invalid_taxonomy';
    case InvalidPublicationDate = 'invalid_publication_date';
    case InvalidDiscussion = 'invalid_discussion';
}
