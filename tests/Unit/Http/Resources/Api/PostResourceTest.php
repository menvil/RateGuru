<?php

use App\Http\Resources\Api\PostResource as ApiPostResource;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('has api post resource', function () {
    $post = Post::factory()->published()->make();

    $resource = new ApiPostResource($post);

    expect($resource)->toBeInstanceOf(ApiPostResource::class);
});

it('resolves api post resource to array', function () {
    $post = Post::factory()->published()->create();

    $data = (new ApiPostResource($post))->resolve();

    expect($data)->toBeArray();
});

it('returns expected api post resource shape', function () {
    $author = User::factory()->create([
        'username' => 'alice',
        'name' => 'Alice Demo',
        'email' => 'alice@example.test',
    ]);

    $tag = Tag::factory()->create([
        'name' => 'Italian',
        'slug' => 'italian',
    ]);

    $post = Post::factory()
        ->for($author, 'user')
        ->published()
        ->create([
            'title' => 'API Shape Post',
            'description' => 'Description for API shape test.',
            'upvotes_count' => 10,
            'downvotes_count' => 2,
            'comments_count' => 5,
            'reports_count' => 99,
            'hot_score' => 0.123456,
        ]);

    $post->tags()->attach($tag);

    $data = (new ApiPostResource($post->load(['user', 'tags'])))->resolve();

    expect($data)->toHaveKeys([
        'id',
        'title',
        'description',
        'image_url',
        'thumbnail_url',
        'canonical_url',
        'author',
        'tags',
        'stats',
        'scores',
        'created_at',
        'published_at',
    ]);

    expect($data)->toMatchArray([
        'title' => 'API Shape Post',
        'description' => 'Description for API shape test.',
    ]);

    expect($data['stats'])->toMatchArray([
        'upvotes_count' => 10,
        'downvotes_count' => 2,
        'comments_count' => 5,
    ]);

    expect($data['scores'])->toHaveKey('hot_score');
    expect($data['tags'][0])->toMatchArray([
        'name' => 'Italian',
        'slug' => 'italian',
    ]);
    expect($data['author'])->toMatchArray([
        'id' => $author->id,
        'username' => 'alice',
        'display_name' => 'Alice Demo',
        'avatar_url' => null,
    ]);
    expect($data['created_at'])->toBeString();
    expect($data['published_at'])->toBeString();

    expect($data)->not->toHaveKey('status');
    expect($data)->not->toHaveKey('reports_count');
    expect($data)->not->toHaveKey('needs_review');
    expect($data)->not->toHaveKey('hidden_at');
    expect($data)->not->toHaveKey('rejected_at');
    expect($data['author'])->not->toHaveKey('email');
});

it('does not force-load author and tags in post resource', function () {
    $post = Post::factory()->published()->create();

    $data = (new ApiPostResource($post))->resolve();

    expect($post->relationLoaded('user'))->toBeFalse();
    expect($post->relationLoaded('tags'))->toBeFalse();
    expect($data['author'])->toBeNull();
    expect($data['tags'])->toBe([]);
});
