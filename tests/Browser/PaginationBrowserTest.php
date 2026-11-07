<?php

use App\Models\Post;

/*
 * Lists drawn a page at a time: the next page starts where a page starts.
 */

it('takes the reader to the top of the next page, not to the foot of it', function () {
    Post::factory()->count(13)->published()->create();
    $page = visit(route('feed'))->resize(1440, 900);

    $page->script('window.scrollTo(0, document.documentElement.scrollHeight)');
    waitForScript($page, 'window.scrollY > 0');

    $page->click('[aria-label="'.__('ui.pagination.next').'"]');

    waitForScript($page, "document.querySelectorAll('[data-testid=\"post-card\"]').length === 1 && window.scrollY === 0");
});
