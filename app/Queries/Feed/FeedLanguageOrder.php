<?php

namespace App\Queries\Feed;

use Carbon\CarbonImmutable;

/**
 * The viewer's language first: what FeedQuery puts ahead of the chosen sort. Fresh posts in the viewer's language —
 * and the viewer's own posts, whatever language they were read as — go first, fresh English ones next, the rest
 * after, each group in the sort's order (docs/architecture/language-ranking.md).
 */
final readonly class FeedLanguageOrder
{
    /** The language every viewer reads after their own. */
    public const SECOND_LANGUAGE = 'en';

    public function __construct(
        public string $viewerLanguage,
        // The signed-in viewer, whose own posts go first; null for a guest.
        public ?int $viewerId,
        // A post counts as fresh from here on; null counts every post (Top, which ranks all time).
        public ?CarbonImmutable $since,
        // In Hot, a post is lifted only from this hot score up; null lifts every one.
        public ?float $hotFloor = null,
    ) {}
}
