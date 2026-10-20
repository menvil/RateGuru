<?php

namespace App\Support\Studio;

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
 * - no prepared discussion, for now: nothing publishes one yet.
 */
final class StudioPlanApprovalPreflight
{
    public function __construct(
        private readonly StudioPlanValidationRules $rules,
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
     * Nothing publishes a discussion yet: the executor runs a plan's post
     * alone. A plan that prepares one is not approved, rather than published
     * without it — until the stage that publishes comments replaces this
     * with the discussion's own checks.
     *
     * @return list<StudioPlanApprovalProblem>
     */
    private function discussionProblems(StudioPlan $plan): array
    {
        return StudioDiscussionNode::query()->where('studio_plan_id', $plan->getKey())->exists()
            ? [StudioPlanApprovalProblem::DiscussionNotPublishable]
            : [];
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
