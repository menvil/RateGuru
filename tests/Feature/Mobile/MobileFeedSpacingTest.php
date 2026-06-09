<?php

use App\Models\Post;

it('feed page includes expected structural testid markers', function () {
    Post::factory()->published()->create(['title' => 'Feed Spacing Test']);

    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="feed-page"', false);
});

it('feed page includes rating filters container', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="feed-rating-filters"', false);
});

it('feed page includes feed layout container', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="feed-layout"', false);
});
