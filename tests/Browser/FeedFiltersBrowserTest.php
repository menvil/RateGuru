<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

/*
 * Narrowing the feed by a sort, a category and a tag, which work together, and
 * widening it again from the tag filter named above the feed.
 */

/** One part of the address the browser is on, or null without it. */
function feedQueryPart(string $name): string
{
    return "new URLSearchParams(location.search).get('{$name}')";
}

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

it('narrows the feed by a sort, a tag and a category together, each choice keeping the others', function () {
    $dinner = Category::factory()->create(['name' => 'Dinner', 'slug' => 'dinner']);
    $pasta = Tag::factory()->create(['slug' => 'pasta']);
    $carbonara = Post::factory()->published()->create(['title' => 'Carbonara', 'category_id' => $dinner->id]);
    $carbonara->tags()->attach($pasta);
    Post::factory()->published()->create(['title' => 'Pasta salad'])->tags()->attach($pasta);
    Post::factory()->published()->create(['title' => 'Lentil soup', 'category_id' => $dinner->id]);

    $page = visit(route('feed'));

    // The sidebar is drawn twice, the mobile drawer's copy hidden.
    $page->click('[data-testid="nav-top"]:visible');
    waitForScript($page, feedQueryPart('sort'), 'top');

    // A tag keeps the sort.
    $page->click("[data-post-id=\"{$carbonara->id}\"] [data-testid=\"post-tag-link\"]");
    waitForScript($page, feedQueryPart('tag'), 'pasta');
    expect($page->script(feedQueryPart('sort')))->toBe('top');
    $page->assertSeeIn('@feed-tag-filter-name', '#pasta')
        ->assertSee('Pasta salad')
        ->assertDontSee('Lentil soup');

    // Another sort keeps the tag.
    $page->click('[data-testid="nav-hot"]:visible');
    waitForScript($page, feedQueryPart('sort'), 'hot');
    expect($page->script(feedQueryPart('tag')))->toBe('pasta');
    $page->assertSeeIn('@feed-tag-filter-name', '#pasta');

    // A category keeps both.
    $page->click("[data-post-id=\"{$carbonara->id}\"] [data-testid=\"post-card-category\"]");
    waitForScript($page, "location.search.includes('dinner')");
    expect($page->script(feedQueryPart('sort')))->toBe('hot')
        ->and($page->script(feedQueryPart('tag')))->toBe('pasta');
    $page->assertSee('Carbonara')
        ->assertDontSee('Pasta salad')
        ->assertDontSee('Lentil soup');

    // Removing the tag keeps the sort and the category.
    $page->click('@feed-tag-filter-clear');
    waitForScript($page, feedQueryPart('tag'), null);
    expect($page->script(feedQueryPart('sort')))->toBe('hot')
        ->and($page->script("location.search.includes('dinner')"))->toBeTrue();
    $page->assertSee('Lentil soup')
        ->assertDontSee('Pasta salad');
});

it('keeps only the parts the feed reads when a post’s tag is followed, by mouse or by keyboard', function () {
    $pasta = Tag::factory()->create(['slug' => 'pasta']);
    $carbonara = Post::factory()->published()->create(['title' => 'Carbonara']);
    $carbonara->tags()->attach($pasta);
    $link = "[data-post-id=\"{$carbonara->id}\"] [data-testid=\"post-tag-link\"]";

    $page = visit(route('feed', ['sort' => 'top', 'utm_source' => 'mail', 'search' => '']));

    $page->click($link);
    waitForScript($page, feedQueryPart('tag'), 'pasta');
    expect($page->script('location.search'))->toBe('?sort=top&tag=pasta');

    // A redraw puts the link's fresh-feed address back while it has the
    // focus; Enter still follows it with the rest of the feed.
    $page = visit(route('feed', ['sort' => 'hot']));
    $page->script("(() => { const link = document.querySelector('{$link}'); link.focus(); link.setAttribute('href', '/?tag=pasta'); return true })()");
    $page->keys($link, 'Enter');
    waitForScript($page, feedQueryPart('tag'), 'pasta');
    expect($page->script(feedQueryPart('sort')))->toBe('hot');
});
