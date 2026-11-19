<?php

namespace App\Support\Games\Flow;

/**
 * What the flow rules need to know of the site beyond the flow itself: the
 * posts its steps link to (their title and whether they are published), the
 * rating groups, categories and tags that are active, and whether each page
 * address its steps name is a page of this site. Read once by
 * GameFlowFactsQuery; the rules stay free of the database.
 */
final readonly class FlowFacts
{
    /**
     * @param  array<int, array{title: string, published: bool}>  $posts
     * @param  list<string>  $ratingGroups
     * @param  list<string>  $categories
     * @param  list<string>  $tags
     * @param  array<string, bool>  $paths
     */
    public function __construct(
        public array $posts = [],
        public array $ratingGroups = [],
        public array $categories = [],
        public array $tags = [],
        public array $paths = [],
    ) {}
}
