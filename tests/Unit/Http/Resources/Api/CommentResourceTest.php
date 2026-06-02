<?php

use App\Http\Resources\Api\CommentResource as ApiCommentResource;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('has api comment resource', function () {
    $comment = Comment::factory()->make();

    $resource = new ApiCommentResource($comment);

    expect($resource)->toBeInstanceOf(ApiCommentResource::class);
});

it('resolves api comment resource to array', function () {
    $comment = Comment::factory()->create();

    $data = (new ApiCommentResource($comment))->resolve();

    expect($data)->toBeArray();
});

it('returns expected api comment resource shape', function () {
    $author = User::factory()->create([
        'username' => 'bob',
        'name' => 'Bob Demo',
        'email' => 'bob@example.test',
    ]);

    $post = Post::factory()->published()->create();

    $comment = Comment::factory()
        ->for($post)
        ->for($author, 'user')
        ->create([
            'body' => 'API comment body.',
            'reports_count' => 7,
        ]);

    $data = (new ApiCommentResource($comment->load('user')))->resolve();

    expect($data)->toHaveKeys([
        'id',
        'post_id',
        'body',
        'author',
        'created_at',
    ]);

    expect($data['post_id'])->toBe($post->id);
    expect($data['body'])->toBe('API comment body.');
    expect($data['author'])->toMatchArray([
        'id' => $author->id,
        'username' => 'bob',
        'display_name' => 'Bob Demo',
        'avatar_url' => null,
    ]);
    expect($data['created_at'])->toBeString();

    expect($data)->not->toHaveKey('status');
    expect($data)->not->toHaveKey('reports_count');
    expect($data)->not->toHaveKey('deleted_at');
    expect($data)->not->toHaveKey('updated_at');
    expect($data['author'])->not->toHaveKey('email');
});

it('does not force-load author in comment resource', function () {
    $comment = Comment::factory()->create();

    $data = (new ApiCommentResource($comment))->resolve();

    expect($comment->relationLoaded('user'))->toBeFalse();
    expect($data['author'])->toBeNull();
});
