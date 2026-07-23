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

    $this->typeGroup = RatingGroup::query()->where('key', 'type')->firstOrFail();
    $this->attributeGroup = RatingGroup::query()->where('key', 'attribute')->firstOrFail();
});

it('persists the author-chosen standalone category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['slug' => 'desserts']);

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Categorised post',
        categoryId: $category->id,
    ));

    expect($post->category_id)->toBe($category->id)
        ->and($post->category->is($category))->toBeTrue();
});

it('rejects an inactive standalone category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->inactive()->create();

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Wrong category post',
        categoryId: $category->id,
    ));
})->throws(CannotCreatePostException::class);

it('persists author answers independently from the post category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $typeOption = $this->typeGroup->options()->where('key', 'type_b')->firstOrFail();
    $attributeOption = $this->attributeGroup->options()->where('key', 'attribute_b')->firstOrFail();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Post with answers',
        categoryId: $category->id,
        authorAnswerOptionIds: [$typeOption->id, $attributeOption->id],
    ));

    $answers = PostAuthorAnswer::query()->where('post_id', $post->id)->get();

    expect($answers)->toHaveCount(2);
    expect($post->category_id)->toBe($category->id);
    expect($answers->firstWhere('rating_group_id', $this->typeGroup->id)->rating_option_id)
        ->toBe($typeOption->id);
    expect($answers->firstWhere('rating_group_id', $this->attributeGroup->id)->rating_option_id)
        ->toBe($attributeOption->id);
});

it('allows creating a post without any author answers', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Post without answers',
    ));

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
    expect($post->category_id)->toBeNull();
});

it('rejects two author answers for the same rating group', function () {
    $user = User::factory()->create();
    $first = $this->typeGroup->options()->where('key', 'type_a')->firstOrFail();
    $second = $this->typeGroup->options()->where('key', 'type_b')->firstOrFail();

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Post with conflicting answers',
        authorAnswerOptionIds: [$first->id, $second->id],
    ));
})->throws(CannotCreatePostException::class);

it('rejects an author answer from an inactive rating group', function () {
    $user = User::factory()->create();

    $inactiveGroup = RatingGroup::factory()->create(['is_active' => false, 'sort_order' => 30]);
    $inactiveGroupOption = RatingOption::factory()->create(['rating_group_id' => $inactiveGroup->id]);

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Post with inactive group',
        authorAnswerOptionIds: [$inactiveGroupOption->id],
    ));
})->throws(CannotCreatePostException::class);

it('deletes author answers together with the post', function () {
    $user = User::factory()->create();
    $typeOption = $this->typeGroup->options()->where('key', 'type_a')->firstOrFail();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Post to delete',
        authorAnswerOptionIds: [$typeOption->id],
    ));

    $post->forceDelete();

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
});
