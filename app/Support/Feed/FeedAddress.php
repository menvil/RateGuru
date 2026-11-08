<?php

namespace App\Support\Feed;

use Illuminate\Http\Request;

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
    /** The sorts App\Livewire\Feed\FeedPage knows; any other falls back to its default. */
    private const SORTS = ['newest', 'top', 'hot'];

    /** @param  array<string, mixed>  $current */
    public function __construct(private readonly array $current) {}

    /**
     * The feed the request shows, as App\Livewire\Feed\FeedPage reads it: a
     * part the feed ignores — a sort it does not know, a list where one value
     * belongs, a category that is not active, a guest's following feed — is
     * left out, so it is neither kept nor shown as chosen. Anywhere but the
     * feed there is nothing to keep.
     *
     * @param  list<string>  $categorySlugs  the active categories
     */
    public static function fromRequest(Request $request, array $categorySlugs): self
    {
        if (! $request->routeIs('feed')) {
            return new self([]);
        }

        $text = fn (string $name): ?string => is_string($value = $request->query($name)) ? $value : null;
        $sort = $text('sort');
        $ratings = $request->query('ratings');

        return new self([
            'search' => $text('search'),
            'tag' => $text('tag'),
            'category' => array_values(array_intersect(
                array_filter((array) $request->query('category'), 'is_string'),
                $categorySlugs,
            )),
            'ratings' => is_array($ratings) ? $ratings : null,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : null,
            'feed' => $text('feed') === 'following' && $request->user() !== null ? 'following' : null,
        ]);
    }

    /** One part of the feed as kept, or null when the feed does not have it. */
    public function part(string $name): mixed
    {
        return $this->current[$name] ?? null;
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
