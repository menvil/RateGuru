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
use App\Models\StudioPlan;
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
    $writes = [];

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    app(StudioPlanApprovalPreflight::class)->problems($plan);

    expect($writes)->toBe([]);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});
