<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Models\PostAuthorAnswer;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;

beforeEach(function () {
    seedFeedFilterGroups();

    $this->sourceGroup = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $this->categoryGroup = RatingGroup::query()->where('key', 'category')->firstOrFail();
});

it('persists the author-chosen category option', function () {
    $user = User::factory()->create();
    $option = $this->sourceGroup->options()->where('key', 'homemade')->firstOrFail();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Categorised dish',
        categoryOptionId: $option->id,
    ));

    expect($post->category_option_id)->toBe($option->id);
    expect($post->categoryOption->key)->toBe('homemade');
});

it('rejects a category option that does not belong to the sidebar group', function () {
    $user = User::factory()->create();
    $secondGroupOption = $this->categoryGroup->options()->where('key', 'italian')->firstOrFail();

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Wrong category dish',
        categoryOptionId: $secondGroupOption->id,
    ));
})->throws(CannotCreatePostException::class);

it('persists author answers, one per rating group', function () {
    $user = User::factory()->create();
    $sourceOption = $this->sourceGroup->options()->where('key', 'restaurant')->firstOrFail();
    $categoryOption = $this->categoryGroup->options()->where('key', 'asian')->firstOrFail();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with answers',
        authorAnswerOptionIds: [$sourceOption->id, $categoryOption->id],
    ));

    $answers = PostAuthorAnswer::query()->where('post_id', $post->id)->get();

    expect($answers)->toHaveCount(2);
    expect($answers->firstWhere('rating_group_id', $this->sourceGroup->id)->rating_option_id)
        ->toBe($sourceOption->id);
    expect($answers->firstWhere('rating_group_id', $this->categoryGroup->id)->rating_option_id)
        ->toBe($categoryOption->id);
});

it('allows creating a post without any author answers', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'No answers dish',
    ));

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
    expect($post->category_option_id)->toBeNull();
});

it('rejects two author answers for the same rating group', function () {
    $user = User::factory()->create();
    $first = $this->sourceGroup->options()->where('key', 'homemade')->firstOrFail();
    $second = $this->sourceGroup->options()->where('key', 'restaurant')->firstOrFail();

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Conflicting answers dish',
        authorAnswerOptionIds: [$first->id, $second->id],
    ));
})->throws(CannotCreatePostException::class);

it('rejects an author answer from an inactive rating group', function () {
    $user = User::factory()->create();

    $inactiveGroup = RatingGroup::factory()->create(['is_active' => false, 'sort_order' => 30]);
    $inactiveGroupOption = RatingOption::factory()->create(['rating_group_id' => $inactiveGroup->id]);

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Inactive group dish',
        authorAnswerOptionIds: [$inactiveGroupOption->id],
    ));
})->throws(CannotCreatePostException::class);

it('deletes author answers together with the post', function () {
    $user = User::factory()->create();
    $sourceOption = $this->sourceGroup->options()->where('key', 'homemade')->firstOrFail();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Doomed dish',
        authorAnswerOptionIds: [$sourceOption->id],
    ));

    $post->forceDelete();

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
});
