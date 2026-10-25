<?php

namespace App\Enums;

use App\Support\Posts\PostContentRules;
use App\Support\Studio\StudioDiscussionValidationRules;
use App\Support\Studio\StudioVoteValidationRules;

/**
 * One reason a plan is not ready to publish, as the approval preflight finds
 * it (StudioPlanApprovalPreflight): a stable code, the editor's field it is
 * about, the rejection it adds up to, and what it says to do.
 */
enum StudioPlanApprovalProblem: string
{
    case AuthorMissing = 'author_missing';
    case AuthorNotPersona = 'author_not_persona';
    case AuthorDeleted = 'author_deleted';
    case AuthorRestricted = 'author_restricted';
    case TitleMissing = 'title_missing';
    case TitleTooShort = 'title_too_short';
    case TitleTooLong = 'title_too_long';
    case DescriptionTooLong = 'description_too_long';
    case SourceUrlInvalid = 'source_url_invalid';
    case PhotoMissing = 'photo_missing';
    case PhotoDeleted = 'photo_deleted';
    case PhotoWrongKind = 'photo_wrong_kind';
    case PhotoNotReady = 'photo_not_ready';
    case PhotoInUse = 'photo_in_use';
    case CategoryInactive = 'category_inactive';
    case TagMissing = 'tag_missing';
    case TagRepeated = 'tag_repeated';
    case TooManyTags = 'too_many_tags';
    case AnswerMissing = 'answer_missing';
    case AnswerInactive = 'answer_inactive';
    case AnswerGroupRepeated = 'answer_group_repeated';
    case PublishAtMissing = 'publish_at_missing';
    case PublishAtOutOfRange = 'publish_at_out_of_range';
    case DiscussionTooLarge = 'discussion_too_large';
    case DiscussionShapeInvalid = 'discussion_shape_invalid';
    case DiscussionTextInvalid = 'discussion_text_invalid';
    case DiscussionPersonaDeleted = 'discussion_persona_deleted';
    case DiscussionPersonaNotPersona = 'discussion_persona_not_persona';
    case DiscussionPersonaRestricted = 'discussion_persona_restricted';
    case DiscussionTimingInvalid = 'discussion_timing_invalid';
    case DiscussionDateOutOfRange = 'discussion_date_out_of_range';
    case VotesTooMany = 'votes_too_many';
    case VoteShapeInvalid = 'vote_shape_invalid';
    case VotePersonaDeleted = 'vote_persona_deleted';
    case VotePersonaNotPersona = 'vote_persona_not_persona';
    case VotePersonaRestricted = 'vote_persona_restricted';
    case VoteOwnContent = 'vote_own_content';
    case VoteRepeated = 'vote_repeated';
    case VoteDirectionInvalid = 'vote_direction_invalid';
    case VoteCommentMissing = 'vote_comment_missing';
    case VoteTimingInvalid = 'vote_timing_invalid';
    case VoteDateOutOfRange = 'vote_date_out_of_range';
    case VoteOptionUnavailable = 'vote_option_unavailable';

    public function rejection(): StudioPlanApprovalRejection
    {
        return match ($this) {
            self::AuthorNotPersona, self::AuthorDeleted, self::AuthorRestricted => StudioPlanApprovalRejection::AuthorUnavailable,
            self::AuthorMissing, self::TitleMissing, self::TitleTooShort, self::TitleTooLong,
            self::DescriptionTooLong, self::SourceUrlInvalid, self::PhotoMissing => StudioPlanApprovalRejection::IncompletePlan,
            self::PhotoDeleted, self::PhotoWrongKind, self::PhotoNotReady, self::PhotoInUse => StudioPlanApprovalRejection::InvalidMedia,
            self::CategoryInactive, self::TagMissing, self::TagRepeated, self::TooManyTags,
            self::AnswerMissing, self::AnswerInactive, self::AnswerGroupRepeated => StudioPlanApprovalRejection::InvalidTaxonomy,
            self::PublishAtMissing, self::PublishAtOutOfRange => StudioPlanApprovalRejection::InvalidPublicationDate,
            self::DiscussionTooLarge, self::DiscussionShapeInvalid, self::DiscussionTextInvalid,
            self::DiscussionPersonaDeleted, self::DiscussionPersonaNotPersona, self::DiscussionPersonaRestricted,
            self::DiscussionTimingInvalid, self::DiscussionDateOutOfRange => StudioPlanApprovalRejection::InvalidDiscussion,
            self::VotesTooMany, self::VoteShapeInvalid, self::VotePersonaDeleted, self::VotePersonaNotPersona,
            self::VotePersonaRestricted, self::VoteOwnContent, self::VoteRepeated, self::VoteDirectionInvalid,
            self::VoteCommentMissing, self::VoteTimingInvalid, self::VoteDateOutOfRange,
            self::VoteOptionUnavailable => StudioPlanApprovalRejection::InvalidVotes,
        };
    }

    /** The editor's field the problem is about, keyed as StudioPlanData and its validation are. */
    public function field(): string
    {
        return match ($this) {
            self::AuthorMissing, self::AuthorNotPersona, self::AuthorDeleted, self::AuthorRestricted => 'authorUserId',
            self::TitleMissing, self::TitleTooShort, self::TitleTooLong => 'title',
            self::DescriptionTooLong => 'description',
            self::SourceUrlInvalid => 'sourceUrl',
            self::PhotoMissing, self::PhotoDeleted, self::PhotoWrongKind, self::PhotoNotReady, self::PhotoInUse => 'photo',
            self::CategoryInactive => 'categoryId',
            self::TagMissing, self::TagRepeated, self::TooManyTags => 'tagIds',
            self::AnswerMissing, self::AnswerInactive, self::AnswerGroupRepeated => 'authorAnswerOptionIds',
            self::PublishAtMissing, self::PublishAtOutOfRange => 'publishAt',
            self::DiscussionTooLarge, self::DiscussionShapeInvalid, self::DiscussionTextInvalid,
            self::DiscussionPersonaDeleted, self::DiscussionPersonaNotPersona, self::DiscussionPersonaRestricted,
            self::DiscussionTimingInvalid, self::DiscussionDateOutOfRange => 'discussion',
            self::VotesTooMany, self::VoteShapeInvalid, self::VotePersonaDeleted, self::VotePersonaNotPersona,
            self::VotePersonaRestricted, self::VoteOwnContent, self::VoteRepeated, self::VoteDirectionInvalid,
            self::VoteCommentMissing, self::VoteTimingInvalid, self::VoteDateOutOfRange, self::VoteOptionUnavailable => 'votes',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::AuthorMissing => 'Choose a persona as the author.',
            self::AuthorNotPersona => 'The author is no longer a persona. Choose another one.',
            self::AuthorDeleted => 'The author’s account was deleted. Choose another persona.',
            self::AuthorRestricted => 'The author is limited, banned or shadowbanned and cannot publish. Choose another persona.',
            self::TitleMissing => 'Write a title.',
            self::TitleTooShort => 'Make the title at least '.PostContentRules::TITLE_MIN_LENGTH.' characters long.',
            self::TitleTooLong => 'Keep the title to '.PostContentRules::TITLE_LIMIT.' characters or fewer.',
            self::DescriptionTooLong => 'Keep the description to '.number_format(PostContentRules::DESCRIPTION_LIMIT).' characters or fewer.',
            self::SourceUrlInvalid => 'Enter the source as a full web address of up to '.number_format(PostContentRules::SOURCE_URL_LIMIT).' characters.',
            self::PhotoMissing => 'Add a photo.',
            self::PhotoDeleted => 'The photo was deleted. Add another one.',
            self::PhotoWrongKind => 'The photo is not a post image. Add another one.',
            self::PhotoNotReady => 'The photo is not ready yet. Wait for it, or add another one.',
            self::PhotoInUse => 'The photo already belongs to a post. Add another one.',
            self::CategoryInactive => 'The category is not active. Choose another one.',
            self::TagMissing => 'A tag no longer exists. Remove it.',
            self::TagRepeated => 'A tag is chosen twice. Remove one.',
            self::TooManyTags => 'Choose up to '.PostContentRules::MOST_TAGS.' tags.',
            self::AnswerMissing => 'An author’s answer no longer exists. Choose another one.',
            self::AnswerInactive => 'An author’s answer is not part of the active rating questions. Choose another one.',
            self::AnswerGroupRepeated => 'Choose one author’s answer for each rating question at most.',
            self::PublishAtMissing => 'Choose the publication date.',
            self::PublishAtOutOfRange => 'Choose a publication date from 1970 to 2038.',
            self::DiscussionTooLarge => 'Keep the discussion to '.StudioDiscussionValidationRules::MOST_NODES.' comments and replies or fewer.',
            self::DiscussionShapeInvalid => 'A reply answers something other than a comment on this post. Fix the discussion.',
            self::DiscussionTextInvalid => 'A comment is empty or longer than '.number_format(StudioDiscussionValidationRules::BODY_LIMIT).' characters. Fix its text.',
            self::DiscussionPersonaDeleted => 'A comment’s persona was deleted. Choose another one.',
            self::DiscussionPersonaNotPersona => 'A comment’s author is no longer a persona. Choose another one.',
            self::DiscussionPersonaRestricted => 'A comment’s persona is limited, banned or shadowbanned and cannot comment. Choose another one.',
            self::DiscussionTimingInvalid => 'A comment comes before the post or more than 30 days after it, or a reply before the comment it answers. Fix its time.',
            self::DiscussionDateOutOfRange => 'A comment would come after 2038, past the latest date a post may carry. Bring it closer to the post.',
            self::VotesTooMany => 'Keep the prepared votes to '.number_format(StudioVoteValidationRules::MOST_VOTES).' or fewer.',
            self::VoteShapeInvalid => 'A prepared vote is not one this plan can cast as it is. Reload the plan and save its votes again.',
            self::VotePersonaDeleted => 'A voting persona was deleted. Choose another one.',
            self::VotePersonaNotPersona => 'A voter is no longer a persona. Choose another one.',
            self::VotePersonaRestricted => 'A voting persona is limited, banned or shadowbanned and cannot vote. Choose another one.',
            self::VoteOwnContent => 'A persona votes on its own post or its own comment. Choose another one.',
            self::VoteRepeated => 'A persona votes on the post, a comment or a rating question twice. Keep one of its votes.',
            self::VoteDirectionInvalid => 'A vote on the post or a comment goes neither up nor down. Choose its way.',
            self::VoteCommentMissing => 'A vote is on a comment this plan does not publish. Reload the plan.',
            self::VoteTimingInvalid => 'A vote comes before the post or the comment it is on, or more than 30 days after the post. Fix its time.',
            self::VoteDateOutOfRange => 'A vote would come after 2038, past the latest date a post may carry. Bring it closer to the post.',
            self::VoteOptionUnavailable => 'A rating vote chooses an option that is archived, gone or not its question’s own, or a question that is not active. Choose another option.',
        };
    }
}
