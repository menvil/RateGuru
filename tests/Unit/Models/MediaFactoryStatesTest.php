<?php

use App\Enums\MediaKind;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('does not attach a media asset to a post by default', function () {
    $post = Post::factory()->create();

    expect($post->image_asset_id)->toBeNull();
});

it('attaches a post_image media asset via the withImage state', function () {
    $post = Post::factory()->withImage()->create();

    expect($post->image_asset_id)->not->toBeNull()
        ->and($post->imageAsset->kind)->toBe(MediaKind::PostImage);
});

it('does not attach a media asset to a user by default', function () {
    $user = User::factory()->create();

    expect($user->avatar_asset_id)->toBeNull();
});

it('attaches an avatar media asset via the withAvatar state', function () {
    $user = User::factory()->withAvatar()->create();

    expect($user->avatar_asset_id)->not->toBeNull()
        ->and($user->avatarAsset->kind)->toBe(MediaKind::Avatar);
});
