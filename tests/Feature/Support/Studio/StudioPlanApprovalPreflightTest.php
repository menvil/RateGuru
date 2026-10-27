<?php

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\StudioPlanApprovalProblem;
use App\Enums\StudioPlanApprovalRejection;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\PersonaProfile;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\Tag;
use App\Models\User;
use App\Support\Studio\StudioPlanApprovalPreflight;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Whether a plan is ready to publish, and every reason it is not — each a
 * stable code — read from the plan and what it names as they are now, with
 * nothing written, queued or sent.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/**
 * What the preflight finds in the plan as stored now.
 *
 * @return list<string>
 */
function approvalProblemsOf(StudioPlan $plan): array
{
    return array_map(
        fn (StudioPlanApprovalProblem $problem): string => $problem->value,
        app(StudioPlanApprovalPreflight::class)->problems(StudioPlan::query()->findOrFail($plan->id)),
    );
}

/** An active rating question with two active answers. */
function activeRatingQuestion(): RatingGroup
{
    $group = RatingGroup::factory()->create(['is_active' => true]);
    RatingOption::factory()->count(2)->create(['rating_group_id' => $group->id, 'is_active' => true]);

    return $group->load('options');
}

it('finds nothing to stop a complete plan published at once, on a past date or on a future one', function (array $when) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $question = activeRatingQuestion();
    $plan = approvableStudioPlan([
        'description' => 'Boats at dawn.',
        'source_url' => 'https://example.com/harbour',
        'category_id' => Category::factory()->create()->id,
        'tag_ids' => Tag::factory()->count(2)->create()->pluck('id')->sort()->values()->all(),
        'author_answer_option_ids' => [$question->options->first()->id],
        ...$when,
    ]);

    expect(approvalProblemsOf($plan))->toBe([]);
})->with([
    'at once' => [['publication_mode' => StudioPlanPublicationMode::Now]],
    'on a past date' => [['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2001-02-03 04:05:06')]],
    'on a future date' => [['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2030-01-01 00:00:00')]],
]);

it('wants an author who is an active Persona able to publish', function (Closure $author, string $problem) {
    $plan = approvableStudioPlan();
    $plan->forceFill(['author_user_id' => $author()?->id])->save();

    expect(approvalProblemsOf($plan))->toBe([$problem]);
})->with([
    'none' => [fn () => null, 'author_missing'],
    'a member' => [fn () => User::factory()->create(), 'author_not_persona'],
    'an administrator' => [fn () => User::factory()->admin()->create(), 'author_not_persona'],
    'a deleted persona' => [fn () => User::factory()->persona()->create(['status' => UserStatus::Deleted]), 'author_deleted'],
    'a limited persona' => [fn () => User::factory()->persona()->create(['status' => UserStatus::Limited]), 'author_restricted'],
    'a banned persona' => [fn () => User::factory()->persona()->create(['status' => UserStatus::Banned]), 'author_restricted'],
    'a shadowbanned persona' => [fn () => User::factory()->persona()->create(['status' => UserStatus::Shadowbanned]), 'author_restricted'],
]);

it('lets a Persona whose replies are switched off publish what an administrator approves', function () {
    $plan = approvableStudioPlan();
    PersonaProfile::factory()->create(['user_id' => $plan->author_user_id]);

    expect($plan->author->personaProfile->engagement->value)->toBe('off')
        ->and(approvalProblemsOf($plan))->toBe([]);
});

it('wants a title of 3 to 120 characters, and a description and source within a post\'s bounds', function (array $texts, array $problems) {
    $plan = approvableStudioPlan($texts);

    expect(approvalProblemsOf($plan))->toBe($problems);
})->with([
    'no title' => [['title' => null], ['title_missing']],
    'a blank title' => [['title' => '   '], ['title_missing']],
    'a title too short' => [['title' => 'Ab'], ['title_too_short']],
    'the shortest title' => [['title' => 'Abc'], []],
    'the longest title' => [['title' => str_repeat('a', 120)], []],
    'a title too long' => [['title' => str_repeat('a', 121)], ['title_too_long']],
    'a description too long' => [['description' => str_repeat('a', 2001)], ['description_too_long']],
    'a source that is no web address' => [['source_url' => 'not a link'], ['source_url_invalid']],
]);

it('wants its photo: a post image, Ready, not deleted and no post\'s already — whoever uploaded it', function (Closure $arrange, array $problems) {
    $plan = approvableStudioPlan();
    $arrange($plan, $plan->imageAsset);

    expect(approvalProblemsOf($plan))->toBe($problems);
})->with([
    'none' => [fn (StudioPlan $plan) => $plan->forceFill(['image_asset_id' => null])->save(), ['photo_missing']],
    'an avatar' => [fn (StudioPlan $plan, MediaAsset $photo) => $photo->forceFill(['kind' => MediaKind::Avatar])->save(), ['photo_wrong_kind']],
    'still processing' => [fn (StudioPlan $plan, MediaAsset $photo) => $photo->forceFill(['status' => MediaStatus::Processing])->save(), ['photo_not_ready']],
    'failed' => [fn (StudioPlan $plan, MediaAsset $photo) => $photo->forceFill(['status' => MediaStatus::Failed])->save(), ['photo_not_ready']],
    'deleted' => [fn (StudioPlan $plan, MediaAsset $photo) => $photo->delete(), ['photo_deleted']],
    'a post\'s already' => [fn (StudioPlan $plan, MediaAsset $photo) => Post::factory()->create(['image_asset_id' => $photo->id]), ['photo_in_use']],
    'a deleted post\'s, still restorable' => [fn (StudioPlan $plan, MediaAsset $photo) => Post::factory()->create(['image_asset_id' => $photo->id])->delete(), ['photo_in_use']],
    // Owned by the administrator who uploaded it, never by the author: that is as it should be.
    'another administrator\'s upload' => [fn (StudioPlan $plan, MediaAsset $photo) => $photo->forceFill(['owner_user_id' => User::factory()->admin()->create()->id])->save(), []],
]);

it('finds nothing to stop a discussion the executor can publish as it is, nor one written for an earlier title', function () {
    [$plan] = approvableStudioPlanWithDiscussion();
    $plan->forceFill(['title' => 'Retitled after the discussion was written'])->save();

    expect(approvalProblemsOf($plan))->toBe([]);
});

it('wants a discussion the executor can publish: the discussion\'s own rules, and every Persona of it able to comment now', function (Closure $arrange, array $problems) {
    $plan = approvableStudioPlan();
    $arrange($plan);

    expect(approvalProblemsOf($plan))->toBe($problems);

    foreach ($problems as $problem) {
        expect(StudioPlanApprovalProblem::from($problem))
            ->rejection()->toBe(StudioPlanApprovalRejection::InvalidDiscussion)
            ->field()->toBe('discussion');
    }
})->with([
    'more than forty comments' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->count(41)->for($plan, 'plan')->create(), ['discussion_too_large']],
    'an empty comment' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['body' => '  ']), ['discussion_text_invalid']],
    'a comment longer than a comment may be' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['body' => str_repeat('a', 1001)]), ['discussion_text_invalid']],
    'a reply to a reply' => [function (StudioPlan $plan) {
        $comment = StudioDiscussionNode::factory()->for($plan, 'plan')->create();
        $reply = StudioDiscussionNode::factory()->replyTo($comment)->create(['offset_seconds' => 120]);
        StudioDiscussionNode::factory()->replyTo($reply)->create(['offset_seconds' => 180]);
    }, ['discussion_shape_invalid']],
    'a reply to another plan\'s comment' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create([
        'parent_id' => StudioDiscussionNode::factory()->for(approvableStudioPlan(), 'plan')->create()->id,
        'offset_seconds' => 120,
    ]), ['discussion_shape_invalid']],
    'a reply before the comment it answers' => [function (StudioPlan $plan) {
        $comment = StudioDiscussionNode::factory()->for($plan, 'plan')->create(['offset_seconds' => 600]);
        StudioDiscussionNode::factory()->replyTo($comment)->create(['offset_seconds' => 300]);
    }, ['discussion_timing_invalid']],
    'a comment more than thirty days after the post' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['offset_seconds' => 2_592_001]), ['discussion_timing_invalid']],
    'a deleted Persona' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['persona_user_id' => User::factory()->persona()->tombstoned()->create()->id]), ['discussion_persona_deleted']],
    'a member, not a Persona' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['persona_user_id' => User::factory()->create()->id]), ['discussion_persona_not_persona']],
    'a limited Persona' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['persona_user_id' => User::factory()->persona()->create(['status' => UserStatus::Limited])->id]), ['discussion_persona_restricted']],
    'a shadowbanned Persona' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['persona_user_id' => User::factory()->persona()->create(['status' => UserStatus::Shadowbanned])->id]), ['discussion_persona_restricted']],
    'a banned Persona' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['persona_user_id' => User::factory()->persona()->create(['status' => UserStatus::Banned])->id]), ['discussion_persona_restricted']],
    'a comment past the latest date a post may carry' => [function (StudioPlan $plan) {
        $plan->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2038-01-18 00:00:00')])->save();
        StudioDiscussionNode::factory()->for($plan, 'plan')->create(['offset_seconds' => 2 * 86_400]);
    }, ['discussion_date_out_of_range']],
    'several at once, each once' => [function (StudioPlan $plan) {
        StudioDiscussionNode::factory()->for($plan, 'plan')->create(['body' => '', 'persona_user_id' => User::factory()->persona()->create(['status' => UserStatus::Limited])->id]);
        StudioDiscussionNode::factory()->for($plan, 'plan')->create(['body' => '']);
    }, ['discussion_text_invalid', 'discussion_persona_restricted']],
]);

it('finds nothing to stop votes of every kind the executor can cast as they are', function () {
    ['plan' => $plan] = approvableStudioPlanWithVotes();

    expect(approvalProblemsOf($plan))->toBe([]);
});

it('stops a plan whose votes cannot be cast as they are, each problem once as its own code, on the votes, adding up to invalid_votes', function (Closure $arrange, array $problems) {
    $prepared = approvableStudioPlanWithVotes();
    $arrange($prepared);

    expect(approvalProblemsOf($prepared['plan']))->toBe($problems);

    foreach ($problems as $code) {
        expect(StudioPlanApprovalProblem::from($code))
            ->field()->toBe('votes')
            ->rejection()->toBe(StudioPlanApprovalRejection::InvalidVotes);
    }
})->with([
    'a voter deleted' => [fn (array $prepared) => $prepared['voters']['down']->forceFill(['status' => UserStatus::Deleted])->save(), ['vote_persona_deleted']],
    'a voter no longer a Persona' => [fn (array $prepared) => $prepared['voters']['down']->forceFill(['role' => 'user'])->save(), ['vote_persona_not_persona']],
    'a limited voter' => [fn (array $prepared) => $prepared['voters']['up']->forceFill(['status' => UserStatus::Limited])->save(), ['vote_persona_restricted']],
    'a shadowbanned voter' => [fn (array $prepared) => $prepared['voters']['up']->forceFill(['status' => UserStatus::Shadowbanned])->save(), ['vote_persona_restricted']],
    'a banned voter' => [fn (array $prepared) => $prepared['voters']['down']->forceFill(['status' => UserStatus::Banned])->save(), ['vote_persona_restricted']],
    'the post\'s own author voting on it' => [fn (array $prepared) => $prepared['plan']->forceFill(['author_user_id' => $prepared['voters']['up']->id])->save(), ['vote_own_content']],
    'a comment\'s own author voting on it' => [fn (array $prepared) => StudioDiscussionNode::query()->whereKey($prepared['saved']->ids['c2'])->update(['persona_user_id' => $prepared['voters']['down']->id]), ['vote_own_content']],
    'a vote before the comment it is on' => [fn (array $prepared) => StudioPlanVote::query()->whereKey($prepared['votes']['c1Up'])->update(['offset_seconds' => 120]), ['vote_timing_invalid']],
    'a vote past the latest date a post may carry' => [function (array $prepared) {
        $prepared['plan']->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2038-01-18 23:29:00')])->save();
        StudioPlanVote::query()->whereKey($prepared['votes']['postDown'])->update(['offset_seconds' => 3_600]);
    }, ['vote_date_out_of_range']],
    'an option archived' => [fn (array $prepared) => $prepared['options'][1]->forceFill(['is_active' => false, 'archived_at' => now()])->save(), ['vote_option_unavailable']],
    'an option deleted' => [fn (array $prepared) => DB::table('rating_options')->where('id', $prepared['options'][1]->id)->delete(), ['vote_option_unavailable']],
    'a rating question no longer active' => [fn (array $prepared) => $prepared['group']->forceFill(['is_active' => false])->save(), ['vote_option_unavailable']],
    'an option now another question\'s' => [fn (array $prepared) => $prepared['options'][0]->forceFill(['rating_group_id' => activeRatingQuestion()->id])->save(), ['vote_option_unavailable']],
    'several at once, each once, in the editor\'s order' => [function (array $prepared) {
        $prepared['voters']['up']->forceFill(['status' => UserStatus::Limited])->save();
        $prepared['options'][0]->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        StudioPlanVote::query()->whereKey($prepared['votes']['c1Up'])->update(['offset_seconds' => 120]);
    }, ['vote_persona_restricted', 'vote_timing_invalid', 'vote_option_unavailable']],
]);

it('stops a plan only for the votes it has: none stops it once they are taken away', function () {
    $plan = approvableStudioPlan();
    $admin = User::factory()->admin()->create();
    $voter = User::factory()->persona()->create();
    saveStudioVotes($admin, $plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $voter->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);
    $voter->forceFill(['status' => UserStatus::Limited])->save();

    expect(approvalProblemsOf($plan))->toBe(['vote_persona_restricted']);

    saveStudioVotes($admin, $plan, []);

    expect(approvalProblemsOf($plan))->toBe([]);
});

it('wants a category, when there is one, that is active', function () {
    $plan = approvableStudioPlan(['category_id' => Category::factory()->inactive()->create()->id]);

    expect(approvalProblemsOf($plan))->toBe(['category_inactive']);

    $plan->forceFill(['category_id' => null])->save();

    expect(approvalProblemsOf($plan))->toBe([]);
});

it('wants tags that exist, each once, ten at most', function (Closure $tagIds, array $problems) {
    $plan = approvableStudioPlan(['tag_ids' => $tagIds()]);

    expect(approvalProblemsOf($plan))->toBe($problems);
})->with([
    'a deleted tag' => [function () {
        $tag = Tag::factory()->create();
        $tag->delete();

        return [$tag->id];
    }, ['tag_missing']],
    'one tag twice' => [function () {
        $tag = Tag::factory()->create();

        return [$tag->id, $tag->id];
    }, ['tag_repeated']],
    'eleven tags' => [fn () => Tag::factory()->count(11)->create()->pluck('id')->sort()->values()->all(), ['too_many_tags']],
    'ten tags' => [fn () => Tag::factory()->count(10)->create()->pluck('id')->sort()->values()->all(), []],
]);

it('wants the author\'s answers among the active rating questions as they are now, one per question', function (Closure $answers, array $problems) {
    $plan = approvableStudioPlan(['author_answer_option_ids' => $answers(activeRatingQuestion())]);

    expect(approvalProblemsOf($plan))->toBe($problems);
})->with([
    'a deleted answer' => [function (RatingGroup $question) {
        $option = $question->options->first();
        $option->delete();

        return [$option->id];
    }, ['answer_missing']],
    'an inactive answer' => [function (RatingGroup $question) {
        $option = $question->options->first();
        $option->forceFill(['is_active' => false])->save();

        return [$option->id];
    }, ['answer_inactive']],
    'an answer to an inactive question' => [function (RatingGroup $question) {
        $question->forceFill(['is_active' => false])->save();

        return [$question->options->first()->id];
    }, ['answer_inactive']],
    'two answers to one question' => [fn (RatingGroup $question) => $question->options->pluck('id')->sort()->values()->all(), ['answer_group_repeated']],
    'one answer to each of two questions' => [fn (RatingGroup $question) => [$question->options->first()->id, activeRatingQuestion()->options->first()->id], []],
]);

it('wants a date a post\'s timestamps hold for a plan published on one', function (?string $publishAt, array $problems) {
    $plan = approvableStudioPlan([
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => $publishAt !== null ? CarbonImmutable::parse($publishAt) : null,
    ]);

    expect(approvalProblemsOf($plan))->toBe($problems);
})->with([
    'no date' => [null, ['publish_at_missing']],
    'before 1970' => ['1969-12-31 23:59:59', ['publish_at_out_of_range']],
    'after 2038' => ['2040-01-01 00:00:00', ['publish_at_out_of_range']],
    'the earliest' => ['1970-01-02 00:00:00', []],
    'the latest' => ['2038-01-18 23:59:59', []],
]);

it('says every problem, each with its field, its reason and what to do', function () {
    $plan = StudioPlan::factory()->create([
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'category_id' => Category::factory()->inactive()->create()->id,
    ]);

    $problems = app(StudioPlanApprovalPreflight::class)->problems($plan);

    expect(array_map(fn (StudioPlanApprovalProblem $problem): string => $problem->value, $problems))
        ->toBe(['author_missing', 'title_missing', 'photo_missing', 'category_inactive', 'publish_at_missing'])
        ->and(array_map(fn (StudioPlanApprovalProblem $problem): string => $problem->field(), $problems))
        ->toBe(['authorUserId', 'title', 'photo', 'categoryId', 'publishAt'])
        ->and(array_map(fn (StudioPlanApprovalProblem $problem): StudioPlanApprovalRejection => $problem->rejection(), $problems))
        ->toBe([
            StudioPlanApprovalRejection::IncompletePlan,
            StudioPlanApprovalRejection::IncompletePlan,
            StudioPlanApprovalRejection::IncompletePlan,
            StudioPlanApprovalRejection::InvalidTaxonomy,
            StudioPlanApprovalRejection::InvalidPublicationDate,
        ]);

    foreach (StudioPlanApprovalProblem::cases() as $problem) {
        expect($problem->message())->toEndWith('.')->not->toBeEmpty();
    }
});

it('only reads: nothing is written, queued, sent or called', function () {
    Queue::fake();
    Bus::fake();
    Http::preventStrayRequests();
    $plan = approvableStudioPlan(['title' => null, 'tag_ids' => [2_000_000_000]]);
    StudioDiscussionNode::factory()->for($plan, 'plan')->create(['persona_user_id' => User::factory()->persona()->create(['status' => UserStatus::Limited])->id]);
    StudioPlanVote::factory()->for($plan, 'plan')->create(['persona_user_id' => User::factory()->persona()->create(['status' => UserStatus::Limited])->id]);
    $writes = [];

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    expect(array_map(fn (StudioPlanApprovalProblem $problem): string => $problem->value, app(StudioPlanApprovalPreflight::class)->problems($plan)))->toContain('vote_persona_restricted')
        ->and($writes)->toBe([]);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});
