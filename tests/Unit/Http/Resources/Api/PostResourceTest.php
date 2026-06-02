<?php

use App\Http\Resources\Api\PostResource as ApiPostResource;
use App\Models\Post;
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
