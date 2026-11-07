<?php

namespace Database\Factories;

use App\Enums\StudioActionType;
use App\Enums\VoteType;
use App\Models\RatingOption;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioPlanVote>
 */
class StudioPlanVoteFactory extends Factory
{
    /**
     * A Persona's up vote on a Draft's post, a minute after it. Its target
     * key is the model's to compute.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'studio_plan_id' => StudioPlan::factory(),
            'persona_user_id' => User::factory()->persona(),
            'type' => StudioActionType::VotePost,
            'vote_type' => VoteType::Up,
            'discussion_node_id' => null,
            'rating_group_id' => null,
            'rating_option_id' => null,
            'sort_order' => 0,
            'offset_seconds' => 60,
        ];
    }

    /** A down vote rather than an up one. */
    public function down(): static
    {
        return $this->state(fn (): array => ['vote_type' => VoteType::Down]);
    }

    /** A vote on this comment or reply, on its plan, a minute after it. */
    public function onComment(StudioDiscussionNode $node): static
    {
        return $this->state(fn (): array => [
            'studio_plan_id' => $node->studio_plan_id,
            'type' => StudioActionType::VoteComment,
            'discussion_node_id' => $node->id,
            'offset_seconds' => $node->offset_seconds + 60,
        ]);
    }

    /** A vote for this rating option, of its rating question. */
    public function forRating(RatingOption $option): static
    {
        return $this->state(fn (): array => [
            'type' => StudioActionType::VoteRating,
            'vote_type' => null,
            'rating_group_id' => $option->rating_group_id,
            'rating_option_id' => $option->id,
        ]);
    }
}
