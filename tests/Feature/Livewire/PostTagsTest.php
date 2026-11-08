<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use App\Models\Tag;
use Database\Seeders\DefaultRatingConfigurationSeeder;
use Livewire\Livewire;

/*
 * A post's tags sit under its picture and above its questions, wherever the
 * post is shown, each one opening the feed filtered by it.
 */

beforeEach(function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);
});

function taggedPost(): Post
{
    $post = Post::factory()->published()->withImage()->create();
    $post->tags()->attach([
        Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta'])->id,
        Tag::factory()->create(['name' => 'Street food', 'slug' => 'street-food'])->id,
    ]);

    return $post;
}

it('shows a post’s tags in the feed under the picture and above the questions', function () {
    taggedPost();

    $this->get(route('feed'))
        ->assertOk()
        ->assertSeeInOrder([
            'data-testid="post-card-image-open"',
            'data-testid="post-card-tags"',
            'href="'.route('feed', ['tag' => 'pasta']).'"',
            '#pasta',
            '#street-food',
            'data-testid="post-card-rating-',
        ], false);
});

it('shows a post’s tags on its page under the picture and above the questions', function () {
    $post = taggedPost();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSeeInOrder([
            'data-testid="post-show-hero"',
            'data-testid="post-show-tags"',
            '#pasta',
            '#street-food',
            'data-testid="post-show-rating-controls"',
        ], false);
});

it('shows a post’s tags in the drawer under the picture and above the questions', function () {
    $post = taggedPost();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSeeInOrder([
            'data-testid="post-drawer-image-open"',
            'data-testid="post-drawer-tags"',
            '#pasta',
            '#street-food',
            'data-testid="post-detail-results"',
        ], false);
});

it('leaves the tag row out of a post that has no tags', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('feed'))->assertOk()->assertDontSee('data-testid="post-card-tags"', false);
    $this->get(route('posts.show', $post))->assertOk()->assertDontSee('data-testid="post-show-tags"', false);
    Livewire::test(PostDrawer::class, ['postId' => $post->id])->assertDontSee('data-testid="post-drawer-tags"', false);
});
