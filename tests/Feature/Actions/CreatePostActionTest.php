<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

it('creates a pending post for normal user', function () {
    $user = User::factory()->create();

    $data = new CreatePostData(
        title: 'Homemade pasta',
        description: null,
        sourceUrl: null,
        originTruth: OriginType::Unknown,
        cuisineTruth: CuisineType::Unknown,
        tagIds: [],
        image: null,
    );

    $post = app(CreatePostAction::class)->handle($user, $data);

    expect($post)->toBeInstanceOf(Post::class);
    expect($post->exists)->toBeTrue();
    expect($post->user_id)->toBe($user->id);
    expect($post->title)->toBe('Homemade pasta');
    expect($post->status)->toBe(PostStatus::Pending);
    expect($post->published_at)->toBeNull();
});

it('creates a published post for trusted user', function () {
    $user = User::factory()->trusted()->create();

    $data = new CreatePostData(title: 'Trusted dish');

    $post = app(CreatePostAction::class)->handle($user, $data);

    expect($post->status)->toBe(PostStatus::Published);
    expect($post->published_at)->not->toBeNull();
});

it('does not allow banned user to create post', function () {
    $user = User::factory()->banned()->create();

    $data = new CreatePostData(title: 'Blocked dish');

    app(CreatePostAction::class)->handle($user, $data);

    expect(Post::query()->count())->toBe(0);
})->throws(CannotCreatePostException::class);

it('persists post description', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Homemade pasta',
        description: 'Fresh pasta with tomato sauce',
    ));

    expect($post->fresh()->description)->toBe('Fresh pasta with tomato sauce');
});

it('persists source url', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Homemade pasta',
        sourceUrl: 'https://example.com/original',
    ));

    expect($post->fresh()->source_url)->toBe('https://example.com/original');
});

it('stores origin truth', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Homemade pasta',
        originTruth: OriginType::Homemade,
    ));

    expect($post->fresh()->origin_truth)->toBe(OriginType::Homemade);
});

it('stores cuisine truth', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Homemade pasta',
        cuisineTruth: CuisineType::Italian,
    ));

    expect($post->fresh()->cuisine_truth)->toBe(CuisineType::Italian);
});

it('attaches tags to created post', function () {
    $user = User::factory()->create();
    $tags = Tag::factory()->count(2)->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Tagged dish',
        tagIds: $tags->pluck('id')->all(),
    ));

    expect($post->tags()->count())->toBe(2);
    expect($post->tags()->pluck('id')->all())
        ->toEqualCanonicalizing($tags->pluck('id')->all());
});
