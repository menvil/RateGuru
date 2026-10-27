<?php

namespace App\Support\Studio;

use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Data\Studio\StudioPlanData;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\StudioPlanApprovalProblem;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Whether a plan is ready to publish — every reason it is not, each as a
 * stable code (StudioPlanApprovalProblem). Asked before an approval is offered
 * and again by the approval itself, on the locked rows.
 *
 * It only reads: no row is written, no post made, no job sent, no rate limit
 * spent and no paid service called.
 *
 * A Draft's own rules (StudioPlanValidationRules) say whether what the plan
 * holds keeps a post's bounds and whether what it names exists; on top of
 * them a plan to approve must be complete and current:
 *
 * - the author is a Persona, not deleted, Active and able to create content
 *   (its replies' Engagement setting plays no part);
 * - a title — the photo is required too: a post image, Ready, not deleted and
 *   not a post's already. Its owner is the administrator who uploaded it and
 *   is never compared with the author;
 * - a category, when there is one, still active; the tags each once; the
 *   author's answers part of the rating questions as they are configured now
 *   (RatingConfigurationManager), one per question;
 * - a date for a plan published on one, past or future alike;
 * - a prepared discussion, when there is one, the executor can publish as it
 *   is: the discussion's own rules (StudioDiscussionValidationRules) — at most
 *   forty comments, two levels, each reply under a comment of this plan and
 *   no earlier than it, texts of a comment's length, moments from the post to
 *   thirty days after it and within the dates a post may carry — and every
 *   Persona of it still one, not deleted, Active and able to comment, as each
 *   must be when its comment is written. A discussion written for an earlier
 *   title is an editor's warning, not a reason to refuse;
 * - prepared votes, when there are any, the executor can cast as they are
 *   (StudioVoteValidationRules::readinessProblems()): at most a thousand,
 *   each of its kind's own shape — up or down on the post or a comment, an
 *   option for a rating question — by a Persona still one, not deleted,
 *   Active and able to vote, never on its own post or comment, once per
 *   target; a comment voted on is one of this plan's discussion, whose action
 *   the approval writes, and comes no later than the vote; no vote before the
 *   post, more than thirty days after it or past the dates a post may carry;
 *   an option still an active one of an active rating question, its own.
 *   Each problem is said once, as its own code (`invalid_votes`).
 */
final class StudioPlanApprovalPreflight
{
    public function __construct(
        private readonly StudioPlanValidationRules $rules,
        private readonly StudioDiscussionValidationRules $discussionRules,
        private readonly StudioVoteValidationRules $voteRules,
        private readonly RatingConfigurationManager $ratingConfiguration,
    ) {}

    /**
     * @return list<StudioPlanApprovalProblem> each once, in the editor's order; none when the plan may be approved
     */
    public function problems(StudioPlan $plan): array
    {
        $failed = $this->failedDraftRules($plan);

        return [
            ...$this->authorProblems($plan),
            ...$this->textProblems($plan, $failed),
            ...$this->photoProblems($plan),
            ...$this->taxonomyProblems($plan, $failed),
            ...$this->dateProblems($plan, $failed),
            ...$this->discussionProblems($plan),
            ...$this->voteProblems($plan),
        ];
    }

    /**
     * Which of a Draft's own rules the plan as stored fails, keyed as the
     * editor's fields — `title`, `tagIds.2` — each with the rules it fails.
     *
     * @return array<string, array<string, mixed>>
     */
    private function failedDraftRules(StudioPlan $plan): array
    {
        $validator = Validator::make(StudioPlanData::of($plan)->toArray(), $this->rules->rules(), $this->rules->messages());
        $validator->passes();

        return $validator->failed();
    }

    /** @return list<StudioPlanApprovalProblem> */
    private function authorProblems(StudioPlan $plan): array
    {
        $author = $plan->author_user_id !== null ? User::query()->find($plan->author_user_id) : null;

        return match (true) {
            $author === null => [StudioPlanApprovalProblem::AuthorMissing],
            $author->isTombstoned() => [StudioPlanApprovalProblem::AuthorDeleted],
            ! $author->isPersona() => [StudioPlanApprovalProblem::AuthorNotPersona],
            $author->status !== UserStatus::Active, ! $author->canCreateContent() => [StudioPlanApprovalProblem::AuthorRestricted],
            default => [],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $failed
     * @return list<StudioPlanApprovalProblem>
     */
    private function textProblems(StudioPlan $plan, array $failed): array
    {
        $problems = [];

        if (StudioPlanData::normalizedText($plan->title) === null) {
            $problems[] = StudioPlanApprovalProblem::TitleMissing;
        } elseif (isset($failed['title'])) {
            $problems[] = isset($failed['title']['Max']) ? StudioPlanApprovalProblem::TitleTooLong : StudioPlanApprovalProblem::TitleTooShort;
        }

        if (isset($failed['description'])) {
            $problems[] = StudioPlanApprovalProblem::DescriptionTooLong;
        }

        if (isset($failed['sourceUrl'])) {
            $problems[] = StudioPlanApprovalProblem::SourceUrlInvalid;
        }

        return $problems;
    }

    /** @return list<StudioPlanApprovalProblem> */
    private function photoProblems(StudioPlan $plan): array
    {
        $photo = $plan->image_asset_id !== null ? MediaAsset::withTrashed()->find($plan->image_asset_id) : null;

        if ($photo === null) {
            return [StudioPlanApprovalProblem::PhotoMissing];
        }

        $problems = [];

        if ($photo->trashed()) {
            $problems[] = StudioPlanApprovalProblem::PhotoDeleted;
        }

        if ($photo->kind !== MediaKind::PostImage) {
            $problems[] = StudioPlanApprovalProblem::PhotoWrongKind;
        }

        if ($photo->status !== MediaStatus::Ready) {
            $problems[] = StudioPlanApprovalProblem::PhotoNotReady;
        }

        // A post deleted but still restorable keeps its photo, as the media
        // lifecycle counts it (MediaReferenceChecker).
        if (Post::withTrashed()->where('image_asset_id', $photo->id)->exists()) {
            $problems[] = StudioPlanApprovalProblem::PhotoInUse;
        }

        return $problems;
    }

    /**
     * @param  array<string, array<string, mixed>>  $failed
     * @return list<StudioPlanApprovalProblem>
     */
    private function taxonomyProblems(StudioPlan $plan, array $failed): array
    {
        $problems = [];

        // A category deleted meanwhile is no category at all (the plan's
        // reference is cleared with it); one there must still be active.
        if ($plan->category_id !== null && ! Category::query()->active()->whereKey($plan->category_id)->exists()) {
            $problems[] = StudioPlanApprovalProblem::CategoryInactive;
        }

        $tagIds = $plan->tag_ids;

        if (isset($failed['tagIds']['List']) || self::failsAnyOf($failed, 'tagIds.')) {
            $problems[] = StudioPlanApprovalProblem::TagMissing;
        }

        if (count($tagIds) !== count(array_unique($tagIds))) {
            $problems[] = StudioPlanApprovalProblem::TagRepeated;
        }

        if (isset($failed['tagIds']['Max'])) {
            $problems[] = StudioPlanApprovalProblem::TooManyTags;
        }

        if (isset($failed['authorAnswerOptionIds']['List']) || self::failsAnyOf($failed, 'authorAnswerOptionIds.')) {
            $problems[] = StudioPlanApprovalProblem::AnswerMissing;
        } elseif ($plan->author_answer_option_ids !== []) {
            // An answer counts only among the active options of the active
            // rating questions, as a post's author answers do.
            $active = $this->ratingConfiguration->allActiveOptionIds();

            if (array_diff($plan->author_answer_option_ids, $active) !== []) {
                $problems[] = StudioPlanApprovalProblem::AnswerInactive;
            }

            if (isset($failed['authorAnswerOptionIds'])) {
                $problems[] = StudioPlanApprovalProblem::AnswerGroupRepeated;
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, array<string, mixed>>  $failed
     * @return list<StudioPlanApprovalProblem>
     */
    private function dateProblems(StudioPlan $plan, array $failed): array
    {
        // Published at once, a plan needs no date; a date in the past is as
        // good as one in the future.
        if ($plan->publication_mode !== StudioPlanPublicationMode::AtDate) {
            return [];
        }

        return match (true) {
            $plan->publish_at === null => [StudioPlanApprovalProblem::PublishAtMissing],
            isset($failed['publishAt']) => [StudioPlanApprovalProblem::PublishAtOutOfRange],
            default => [],
        };
    }

    /**
     * What stands in the way of publishing the plan's discussion as it is,
     * each problem once, in the order of StudioPlanApprovalProblem: the
     * discussion's own rules first, then its Personas as they are now, then
     * its comments' dates against the post's.
     *
     * @return list<StudioPlanApprovalProblem>
     */
    private function discussionProblems(StudioPlan $plan): array
    {
        $nodes = StudioDiscussionNode::query()->where('studio_plan_id', $plan->getKey())->orderBy('id')->get();

        if ($nodes->isEmpty()) {
            return [];
        }

        $problems = [];

        try {
            $this->discussionRules->validated(new StudioDiscussionData($nodes->map(fn (StudioDiscussionNode $node): StudioDiscussionNodeData => StudioDiscussionNodeData::of($node))->values()->all()));
        } catch (ValidationException $invalid) {
            foreach (array_keys($invalid->errors()) as $field) {
                $problems[] = match (true) {
                    $field === 'nodes' => StudioPlanApprovalProblem::DiscussionTooLarge,
                    str_ends_with($field, '.body') => StudioPlanApprovalProblem::DiscussionTextInvalid,
                    str_ends_with($field, '.offsetSeconds') => StudioPlanApprovalProblem::DiscussionTimingInvalid,
                    // Asked of the Personas as they are now, below.
                    str_ends_with($field, '.personaUserId') => null,
                    default => StudioPlanApprovalProblem::DiscussionShapeInvalid,
                };
            }
        }

        $personas = User::query()->whereKey($nodes->pluck('persona_user_id')->unique()->values()->all())->get()->keyBy('id');

        foreach ($nodes->pluck('persona_user_id')->unique() as $id) {
            $persona = $personas->get($id);

            $problems[] = match (true) {
                $persona === null, $persona->isTombstoned() => StudioPlanApprovalProblem::DiscussionPersonaDeleted,
                ! $persona->isPersona() => StudioPlanApprovalProblem::DiscussionPersonaNotPersona,
                $persona->status !== UserStatus::Active, ! $persona->canComment() => StudioPlanApprovalProblem::DiscussionPersonaRestricted,
                default => null,
            };
        }

        foreach ($nodes as $node) {
            if (! StudioDiscussionValidationRules::fitsPostDates($plan->publication_mode, $plan->publish_at, $node->offset_seconds)) {
                $problems[] = StudioPlanApprovalProblem::DiscussionDateOutOfRange;
            }
        }

        return array_values(array_filter(
            StudioPlanApprovalProblem::cases(),
            fn (StudioPlanApprovalProblem $problem): bool => in_array($problem, $problems, true),
        ));
    }

    /**
     * What stands in the way of casting the plan's prepared votes as they
     * are, each problem once, in the order of StudioPlanApprovalProblem — the
     * same checks the votes are held to before they may be cast
     * (StudioVoteValidationRules::readinessProblems()).
     *
     * @return list<StudioPlanApprovalProblem>
     */
    private function voteProblems(StudioPlan $plan): array
    {
        $problems = array_values($this->voteRules->readinessProblems($plan));

        return array_values(array_filter(
            StudioPlanApprovalProblem::cases(),
            fn (StudioPlanApprovalProblem $problem): bool => in_array($problem, $problems, true),
        ));
    }

    /** @param  array<string, array<string, mixed>>  $failed */
    private static function failsAnyOf(array $failed, string $prefix): bool
    {
        foreach (array_keys($failed) as $attribute) {
            if (str_starts_with($attribute, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
