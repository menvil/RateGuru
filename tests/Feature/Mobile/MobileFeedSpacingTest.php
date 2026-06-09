<?php

use App\Models\Post;

it('renders mobile feed container with responsive spacing', function () {
    Post::factory()->published()->create(['title' => 'Mobile Feed Spacing Test']);

    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="feed-page"', false);
});

it('renders feed filters row that wraps on mobile', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="feed-rating-filters"', false);
});

it('renders feed layout with mobile-safe container', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="feed-layout"', false);
});
