<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Queries\Posts\PublishedPostDetailsQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('loads published post details and their required relationships', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $post = Post::factory()
        ->for($user)
        ->for($category)
        ->hasAttached($tag)
        ->published()
        ->create();

    $result = app(PublishedPostDetailsQuery::class)->find($post->id);

    expect($result?->is($post))->toBeTrue()
        ->and($result?->user->is($user))->toBeTrue()
        ->and($result?->category->is($category))->toBeTrue()
        ->and($result?->tags->contains(
            fn (Tag $attachedTag): bool => $attachedTag->is($tag),
        ))->toBeTrue()
        ->and($result?->relationLoaded('user'))->toBeTrue()
        ->and($result?->relationLoaded('tags'))->toBeTrue()
        ->and($result?->relationLoaded('category'))->toBeTrue();
});

it('preserves nullable and fail-fast lookups for unpublished posts', function () {
    $post = Post::factory()->pending()->create();
    $query = app(PublishedPostDetailsQuery::class);

    expect($query->find($post->id))->toBeNull();
    expect(fn () => $query->findOrFail($post->id))
        ->toThrow(ModelNotFoundException::class);
});
