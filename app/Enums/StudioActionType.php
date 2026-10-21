<?php

namespace App\Enums;

/**
 * What one scheduled write of a publication plan does
 * (docs/architecture/studio-plans.md, "The action it writes"): publish the
 * plan's post, comment on it, reply to a comment, or vote on the post, a
 * comment or one of the post's rating questions — each through the existing
 * domain action that does the same for a member.
 *
 * The whole set is the business queue's format. Approving a plan makes its
 * CreatePost and a CreateComment or CreateReply for each comment of its
 * prepared discussion, and the executor runs them; nothing makes or runs the
 * votes yet. The three votes are also the kinds of vote a Draft prepares
 * (StudioPlanVote).
 */
enum StudioActionType: string
{
    case CreatePost = 'create_post';
    case CreateComment = 'create_comment';
    case CreateReply = 'create_reply';
    case VotePost = 'vote_post';
    case VoteComment = 'vote_comment';
    case VoteRating = 'vote_rating';

    /** Whether it is one of the three votes rather than a post, comment or reply. */
    public function isVote(): bool
    {
        return in_array($this, [self::VotePost, self::VoteComment, self::VoteRating], true);
    }
}
