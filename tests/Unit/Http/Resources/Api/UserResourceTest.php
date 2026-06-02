<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Resources\Api\CommentResource as ApiCommentResource;
use App\Http\Resources\Api\PostResource as ApiPostResource;
use App\Http\Resources\Api\UserResource as ApiUserResource;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('has api user resource', function () {
    $user = User::factory()->make();

    $resource = new ApiUserResource($user);

    expect($resource)->toBeInstanceOf(ApiUserResource::class);
});

it('resolves api user resource to array', function () {
    $user = User::factory()->create();

    $data = (new ApiUserResource($user))->resolve();

    expect($data)->toBeArray();
});

it('returns expected api user resource shape', function () {
    $user = User::factory()->create([
        'username' => 'alice',
        'name' => 'Alice Demo',
        'email' => 'alice@example.test',
        'role' => UserRole::Admin,
        'status' => UserStatus::Banned,
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    $data = (new ApiUserResource($user))->resolve();

    expect($data)->toHaveKeys([
        'id',
        'username',
        'display_name',
        'avatar_url',
        'profile_url',
    ]);

    expect($data)->toMatchArray([
        'id' => $user->id,
        'username' => 'alice',
        'display_name' => 'Alice Demo',
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    expect($data)->not->toHaveKey('email');
    expect($data)->not->toHaveKey('role');
    expect($data)->not->toHaveKey('status');
    expect($data)->not->toHaveKey('reports_count');
    expect($data)->not->toHaveKey('trust_level');
});

it('includes public profile url when username is available', function () {
    $user = User::factory()->create([
        'username' => 'alice',
    ]);

    $data = (new ApiUserResource($user))->resolve();

    expect($data['profile_url'])->toContain('/u/alice');
});

it('uses user resource shape for post and comment authors', function () {
    $author = User::factory()->create([
        'username' => 'author',
        'name' => 'Author Demo',
        'email' => 'author@example.test',
    ]);

    $post = Post::factory()
        ->for($author, 'user')
        ->published()
        ->create();

    $comment = Comment::factory()
        ->for($post)
        ->for($author, 'user')
        ->create();

    $postAuthor = (new ApiPostResource($post->load('user')))->resolve()['author'];
    $commentAuthor = (new ApiCommentResource($comment->load('user')))->resolve()['author'];
    $expectedKeys = ['id', 'username', 'display_name', 'avatar_url', 'profile_url'];

    expect(array_keys($postAuthor))->toBe($expectedKeys);
    expect(array_keys($commentAuthor))->toBe($expectedKeys);
    expect($postAuthor)->not->toHaveKey('email');
    expect($commentAuthor)->not->toHaveKey('email');
});
