<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Models\Post;
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
