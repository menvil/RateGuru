<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Models\Category;
use App\Models\PostAuthorAnswer;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;

beforeEach(function () {
    seedFeedFilterGroups();

    $this->sourceGroup = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $this->categoryGroup = RatingGroup::query()->where('key', 'category')->firstOrFail();
});

it('persists the author-chosen standalone category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['slug' => 'desserts']);

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Categorised dish',
        categoryId: $category->id,
    ));

    expect($post->category_id)->toBe($category->id)
        ->and($post->category->is($category))->toBeTrue();
});

it('rejects an inactive standalone category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->inactive()->create();

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Wrong category dish',
        categoryId: $category->id,
    ));
})->throws(CannotCreatePostException::class);

it('persists author answers independently from the post category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $sourceOption = $this->sourceGroup->options()->where('key', 'source_b')->firstOrFail();
    $categoryOption = $this->categoryGroup->options()->where('key', 'category_b')->firstOrFail();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with answers',
        categoryId: $category->id,
        authorAnswerOptionIds: [$sourceOption->id, $categoryOption->id],
    ));

    $answers = PostAuthorAnswer::query()->where('post_id', $post->id)->get();

    expect($answers)->toHaveCount(2);
    expect($post->category_id)->toBe($category->id);
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
    expect($post->category_id)->toBeNull();
});

it('rejects two author answers for the same rating group', function () {
    $user = User::factory()->create();
    $first = $this->sourceGroup->options()->where('key', 'source_a')->firstOrFail();
    $second = $this->sourceGroup->options()->where('key', 'source_b')->firstOrFail();

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
    $sourceOption = $this->sourceGroup->options()->where('key', 'source_a')->firstOrFail();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Doomed dish',
        authorAnswerOptionIds: [$sourceOption->id],
    ));

    $post->forceDelete();

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
});
