<?php

use App\Models\Post;
use App\Queries\Feed\FeedQuery;

/*
 * Lists drawn a page at a time: the next page starts where a page starts.
 */

it('takes the reader to the top of the next page, not to the foot of it', function () {
    // One more than a page, as the feed itself sizes a page here.
    Post::factory()->count(app(FeedQuery::class)->paginate()->perPage() + 1)->published()->create();
    $page = visit(route('feed'))->resize(1440, 900);

    $page->script('window.scrollTo(0, document.documentElement.scrollHeight)');
    waitForScript($page, 'window.scrollY > 0');

    $page->click('[aria-label="'.__('ui.pagination.next').'"]');

    // The next page drawn, and then the reader at its top: each its own failure.
    waitForScript($page, "document.querySelectorAll('[data-testid=\"post-card\"]').length === 1");
    waitForScript($page, 'window.scrollY === 0');
});
