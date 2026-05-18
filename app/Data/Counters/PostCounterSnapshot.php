<?php

namespace App\Data\Counters;

final readonly class PostCounterSnapshot
{
    /**
     * @param  array<string,int>  $cuisineVotes
     */
    public function __construct(
        public int $upvotes,
        public int $downvotes,
        public int $homemadeVotes,
        public int $restaurantVotes,
        public array $cuisineVotes = [],
    ) {}
}
