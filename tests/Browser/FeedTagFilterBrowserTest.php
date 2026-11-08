<?php

use App\Models\Post;
use App\Models\Tag;

/*
 * Narrowing the feed to a tag from a post, and widening it again from the
 * filter named above the feed.
 */

it('filters the feed by a post’s tag and shows every post again once the tag is removed', function () {
    $pasta = Tag::factory()->create(['slug' => 'pasta']);
    Post::factory()->published()->create(['title' => 'Carbonara'])->tags()->attach($pasta);
    Post::factory()->published()->create(['title' => 'Lentil soup']);

    $page = visit(route('feed'))
        ->assertSee('Lentil soup')
        ->assertMissing('@feed-tag-filter');

    $page->click('[data-testid="post-card-tags"] [data-testid="post-tag-link"]');

    waitForScript($page, 'document.querySelector(\'[data-testid="feed-tag-filter-name"]\')?.textContent.trim() ?? null', '#pasta');
    $page->assertQueryStringHas('tag', 'pasta')
        ->assertSee('Carbonara')
        ->assertDontSee('Lentil soup');

    $page->click('@feed-tag-filter-clear');

    waitForScript($page, 'document.querySelector(\'[data-testid="feed-tag-filter"]\') === null');
    $page->assertQueryStringMissing('tag')
        ->assertSee('Carbonara')
        ->assertSee('Lentil soup');
});
