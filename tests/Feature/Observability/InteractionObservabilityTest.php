<?php

use App\Actions\Follows\FollowAuthorAction;
use App\Actions\Follows\UnfollowAuthorAction;
use App\Actions\Posts\SavePostAction;
use App\Actions\Posts\UnsavePostAction;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Support\Facades\Log;

it('logs saved post action', function () {
    Log::spy();

    ProjectSettings::factory()->create([
        'feature_flags' => ['show_saved_posts' => true],
    ]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(SavePostAction::class)->handle($user, $post);

    Log::shouldHaveReceived('info')
        ->with('saved_posts.saved', Mockery::any());
});

it('logs unsaved post action', function () {
    Log::spy();

    ProjectSettings::factory()->create([
        'feature_flags' => ['show_saved_posts' => true],
    ]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(UnsavePostAction::class)->handle($user, $post);

    Log::shouldHaveReceived('info')
        ->with('saved_posts.unsaved', Mockery::any());
});

it('logs follow action', function () {
    Log::spy();

    ProjectSettings::factory()->create([
        'feature_flags' => ['show_follow_buttons' => true],
    ]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    app(FollowAuthorAction::class)->handle($follower, $author);

    Log::shouldHaveReceived('info')
        ->with('follows.followed', Mockery::any());
});

it('logs unfollow action', function () {
    Log::spy();

    ProjectSettings::factory()->create([
        'feature_flags' => ['show_follow_buttons' => true],
    ]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    app(UnfollowAuthorAction::class)->handle($follower, $author);

    Log::shouldHaveReceived('info')
        ->with('follows.unfollowed', Mockery::any());
});
