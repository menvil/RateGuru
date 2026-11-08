<?php

namespace App\Support\Feed;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Addresses of the feed that change one part of the feed a visitor is on — its
 * sort, the following feed, a category, a tag — and keep every other part, so
 * a sort, a category and a tag narrow the feed together. The page number is
 * never kept: a changed feed starts on its first page.
 *
 * resources/js/feed-links.js applies the same rule in the browser to the tag
 * and category links inside posts, which Livewire also draws on requests that
 * do not carry the feed's address.
 */
final class FeedAddress
{
    /** The query parameters the feed reads (App\Livewire\Feed\FeedPage). */
    private const PARTS = ['search', 'tag', 'category', 'ratings', 'sort', 'feed'];

    /** @param  array<string, mixed>  $current */
    public function __construct(private readonly array $current) {}

    /** The feed the request shows, or, anywhere but the feed, nothing to keep. */
    public static function fromRequest(Request $request): self
    {
        return new self($request->routeIs('feed') ? Arr::only($request->query(), self::PARTS) : []);
    }

    /**
     * The address with these parts changed; a null, empty string or empty
     * list removes a part.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): string
    {
        return route('feed', array_filter(
            array_merge($this->current, $changes),
            fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        ));
    }
}
