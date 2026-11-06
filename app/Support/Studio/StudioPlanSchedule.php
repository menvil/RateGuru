<?php

namespace App\Support\Studio;

use App\Data\Studio\PlannedStudioAction;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanPublicationMode;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use Carbon\CarbonImmutable;

/**
 * Every action a plan's approval writes, worked out from what the plan holds
 * now (docs/architecture/studio-plans.md, "The action it writes") — the one
 * source of that set: ApproveStudioPlanAction writes exactly these, and the
 * Publication step's Action plan shows exactly these before anything is
 * written. It only reads.
 *
 * In the order they are written: the CreatePost; a CreateComment under it for
 * each comment of the discussion, then a CreateReply under its comment for
 * each reply — each by its own Persona, its text and its comment's id kept in
 * its payload — and one action for each prepared vote, by its Persona: a
 * VotePost or a VoteRating under the CreatePost, a VoteComment under the
 * action of the comment it is on, found by the comment's id. Comments and
 * votes are each written by time, then place, then id, so those due at once
 * are sent in the order they come.
 *
 * The moment of approval decides when each may run. Published at once, the
 * post happens then and may run from then. On a date, the post happens on
 * that date; a past date may run at once, a future one only from that date.
 * Each comment and each vote happens its `offset_seconds` after the post, and
 * may run from then, or from the approval when that is later — and never
 * before the action it depends on is done, which the dispatcher asks besides.
 */
final class StudioPlanSchedule
{
    /**
     * @return non-empty-list<PlannedStudioAction> the post's first
     */
    public function of(StudioPlan $plan, CarbonImmutable $approvedAt): array
    {
        [$occursAt, $runAfter] = self::moments($plan, $approvedAt);

        $post = new PlannedStudioAction('post', null, StudioActionType::CreatePost, $plan->author_user_id, [], $occursAt, $runAfter);

        return [
            $post,
            ...self::discussionActions($plan, $occursAt, $approvedAt),
            ...self::voteActions($plan, $occursAt, $approvedAt),
        ];
    }

    /**
     * When the post happens, and when it may run.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function moments(StudioPlan $plan, CarbonImmutable $approvedAt): array
    {
        if ($plan->publication_mode !== StudioPlanPublicationMode::AtDate || $plan->publish_at === null) {
            return [$approvedAt, $approvedAt];
        }

        $occursAt = $plan->publish_at->utc()->startOfSecond();

        return [$occursAt, self::runAfter($occursAt, $approvedAt)];
    }

    /**
     * The comments of the discussion, then the replies — each reply under its
     * comment, taken from the discussion's own tree, never from its text or
     * its order.
     *
     * @return list<PlannedStudioAction>
     */
    private static function discussionActions(StudioPlan $plan, CarbonImmutable $postOccursAt, CarbonImmutable $approvedAt): array
    {
        $nodes = StudioDiscussionNode::query()
            ->where('studio_plan_id', $plan->id)
            ->orderBy('offset_seconds')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $planned = [];

        foreach ([false, true] as $replies) {
            foreach ($nodes as $node) {
                if ($node->isRoot() === $replies) {
                    continue;
                }

                $occursAt = $postOccursAt->addSeconds($node->offset_seconds);
                $planned[] = new PlannedStudioAction(
                    'node:'.$node->id,
                    $replies ? 'node:'.$node->parent_id : 'post',
                    $replies ? StudioActionType::CreateReply : StudioActionType::CreateComment,
                    $node->persona_user_id,
                    ['discussion_node_id' => $node->id, 'body' => trim($node->body)],
                    $occursAt,
                    self::runAfter($occursAt, $approvedAt),
                );
            }
        }

        return $planned;
    }

    /**
     * A vote's payload keeps only what was approved — its way or its option,
     * its question, its comment's id and the prepared vote's own id — never a
     * post, a comment or a user a run would act on.
     *
     * @return list<PlannedStudioAction>
     */
    private static function voteActions(StudioPlan $plan, CarbonImmutable $postOccursAt, CarbonImmutable $approvedAt): array
    {
        $votes = StudioPlanVote::query()
            ->where('studio_plan_id', $plan->id)
            ->orderBy('offset_seconds')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $planned = [];

        foreach ($votes as $vote) {
            [$parentKey, $payload] = match ($vote->type) {
                StudioActionType::VotePost => ['post', ['vote_type' => $vote->vote_type?->value, 'studio_plan_vote_id' => $vote->id]],
                StudioActionType::VoteComment => ['node:'.(int) $vote->discussion_node_id, [
                    'vote_type' => $vote->vote_type?->value,
                    'discussion_node_id' => $vote->discussion_node_id,
                    'studio_plan_vote_id' => $vote->id,
                ]],
                StudioActionType::VoteRating => ['post', [
                    'rating_group_id' => $vote->rating_group_id,
                    'rating_option_id' => $vote->rating_option_id,
                    'studio_plan_vote_id' => $vote->id,
                ]],
                // Not a vote: a row no save writes, which the preflight refuses.
                default => [null, []],
            };

            $occursAt = $postOccursAt->addSeconds($vote->offset_seconds);
            $planned[] = new PlannedStudioAction('vote:'.$vote->id, $parentKey, $vote->type, $vote->persona_user_id, $payload, $occursAt, self::runAfter($occursAt, $approvedAt));
        }

        return $planned;
    }

    /** A moment past may run from the approval; one still to come waits for itself. */
    private static function runAfter(CarbonImmutable $occursAt, CarbonImmutable $approvedAt): CarbonImmutable
    {
        return $occursAt->greaterThan($approvedAt) ? $occursAt : $approvedAt;
    }
}
