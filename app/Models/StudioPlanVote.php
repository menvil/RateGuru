<?php

namespace App\Models;

use App\Enums\StudioActionType;
use App\Enums\VoteType;
use Carbon\CarbonImmutable;
use Database\Factories\StudioPlanVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One vote a Draft prepares (docs/architecture/studio-plans.md, "The
 * votes"): a Persona's intention to vote on the plan's post, on one comment
 * of its prepared discussion, or for one option of a rating question — the
 * same three votes a member casts (VotePostAction, VoteCommentAction,
 * VoteRatingOptionAction), never a vote of a kind of its own. It is an
 * intention only: no vote exists for it, and saving one casts nothing.
 *
 * - `vote_post`: up or down on the post;
 * - `vote_comment`: up or down on a comment or reply of the discussion,
 *   named by its node — no comment exists for it yet;
 * - `vote_rating`: one option of one rating question, by their ids.
 *
 * A Persona votes on each target once whichever way it goes, so the target
 * is kept as a key of its own (`target_key`): `post`, `comment:{node}`,
 * `rating:{group}`. The key is never taken from a caller: it is computed from
 * the target every time the row is saved (targetKeyFor()).
 *
 * Its id is kept through every edit. Written only by SaveStudioVotesAction,
 * under the plan's lock and version. Nothing is mass assignable: every
 * column is the server's to write.
 *
 * @property int $id
 * @property int $studio_plan_id
 * @property int $persona_user_id
 * @property StudioActionType $type
 * @property VoteType|null $vote_type
 * @property int|null $discussion_node_id
 * @property int|null $rating_group_id
 * @property int|null $rating_option_id
 * @property string $target_key
 * @property int $offset_seconds
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class StudioPlanVote extends Model
{
    /** @use HasFactory<StudioPlanVoteFactory> */
    use HasFactory;

    protected $table = 'studio_plan_votes';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (StudioPlanVote $vote): void {
            $vote->target_key = self::targetKeyFor($vote->type, $vote->discussion_node_id, $vote->rating_group_id);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'studio_plan_id' => 'integer',
            'persona_user_id' => 'integer',
            'type' => StudioActionType::class,
            'vote_type' => VoteType::class,
            'discussion_node_id' => 'integer',
            'rating_group_id' => 'integer',
            'rating_option_id' => 'integer',
            'offset_seconds' => 'integer',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * What a vote of this kind is on, as one key: the post, one comment of
     * the discussion, or one rating question — never the direction or the
     * option, so a Persona's second vote on one target is a second vote, not
     * a different one.
     *
     * @throws LogicException for anything that is not one of the three votes
     */
    public static function targetKeyFor(?StudioActionType $type, ?int $discussionNodeId, ?int $ratingGroupId): string
    {
        return match ($type) {
            StudioActionType::VotePost => 'post',
            StudioActionType::VoteComment => 'comment:'.$discussionNodeId,
            StudioActionType::VoteRating => 'rating:'.$ratingGroupId,
            default => throw new LogicException('A prepared vote is on the post, a comment or a rating question.'),
        };
    }

    /** @return BelongsTo<StudioPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudioPlan::class, 'studio_plan_id');
    }

    /**
     * The Persona who is to vote. It keeps its Persona through a sanction or
     * a deletion of the account; whether that Persona can still vote is
     * asked before the vote is cast.
     *
     * @return BelongsTo<User, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(User::class, 'persona_user_id');
    }

    /**
     * The prepared comment or reply it is on, for a vote on a comment.
     *
     * @return BelongsTo<StudioDiscussionNode, $this>
     */
    public function discussionNode(): BelongsTo
    {
        return $this->belongsTo(StudioDiscussionNode::class, 'discussion_node_id');
    }

    /**
     * The rating question, for a vote for a rating option — none once it is
     * deleted.
     *
     * @return BelongsTo<RatingGroup, $this>
     */
    public function ratingGroup(): BelongsTo
    {
        return $this->belongsTo(RatingGroup::class, 'rating_group_id');
    }

    /**
     * The option chosen — none once it is deleted. An archived one is still
     * the one chosen: nothing ever replaces it with another.
     *
     * @return BelongsTo<RatingOption, $this>
     */
    public function ratingOption(): BelongsTo
    {
        return $this->belongsTo(RatingOption::class, 'rating_option_id');
    }
}
