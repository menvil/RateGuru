<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

/*
 * The tag a feed is filtered by is named above it, with a way back to the same
 * feed without it.
 */

const TAG_FILTER_CLEAR = '//*[@data-testid="feed-tag-filter-clear"]';

it('names the tag above the feed, with a link back to the feed without it', function () {
    $tag = Tag::factory()->create(['slug' => 'pasta']);
    Post::factory()->published()->create(['title' => 'Carbonara'])->tags()->attach($tag);

    $html = $this->get('/?tag=pasta')
        ->assertOk()
        ->assertSeeInOrder(['data-testid="feed-tag-filter"', __('ui.feed.tag_filter'), '#pasta', 'Carbonara'], false)
        ->getContent();

    $clear = livewireDom($html)->query(TAG_FILTER_CLEAR)->item(0);

    expect($clear)->not->toBeNull()
        ->and($clear->getAttribute('href'))->toBe(route('feed'))
        ->and($clear->getAttribute('aria-label'))->toBe(__('ui.feed.clear_tag_filter', ['tag' => '#pasta']));
});

it('keeps the rest of the feed’s filters when the tag is removed', function () {
    Category::factory()->create(['slug' => 'dinner']);

    $html = $this->get('/?tag=pasta&category[]=dinner&sort=top&search=sauce')->assertOk()->getContent();

    expect(livewireDom($html)->query(TAG_FILTER_CLEAR)->item(0)?->getAttribute('href'))
        ->toBe(route('feed', ['search' => 'sauce', 'category' => ['dinner'], 'sort' => 'top']));
});

it('shows no tag filter on a feed that is not filtered by a tag', function (string $url) {
    Category::factory()->create(['slug' => 'dinner']);

    $this->get($url)->assertOk()->assertDontSee('data-testid="feed-tag-filter"', false);
})->with([
    'the whole feed' => ['/'],
    'a category' => ['/?category[]=dinner'],
    'a search' => ['/?search=sauce'],
]);
