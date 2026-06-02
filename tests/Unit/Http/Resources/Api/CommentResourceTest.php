<?php

use App\Http\Resources\Api\CommentResource as ApiCommentResource;
use App\Models\Comment;
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
