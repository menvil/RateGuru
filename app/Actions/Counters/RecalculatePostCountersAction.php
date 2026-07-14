<?php

namespace App\Actions\Counters;

use App\Data\Counters\PostCounterSnapshot;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\VoteType;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\PostVote;
use Illuminate\Support\Facades\DB;

class RecalculatePostCountersAction
{
    public function handle(Post $post): PostCounterSnapshot
    {
        return DB::transaction(function () use ($post) {
            // Pessimistically lock the posts row before counting. This
            // serializes concurrent voters/recalculations for the same post:
            // a second transaction blocks here until the first commits, so its
            // COUNT(*) sees the already-committed vote rows. Without this lock
            // the unlocked read-then-write loses updates under concurrency and
            // leaves persisted counters stale. When called from inside a vote
            // transaction this nests as a savepoint and the row lock is held
            // by the outer transaction until it commits.
            Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->first();

            $upvotes = PostVote::query()
                ->where('post_id', $post->id)
                ->where('type', VoteType::Up)
                ->count();

            $downvotes = PostVote::query()
                ->where('post_id', $post->id)
                ->where('type', VoteType::Down)
                ->count();

            $homemadeVotes = OriginVote::query()
                ->where('post_id', $post->id)
                ->where('origin', OriginType::Homemade)
                ->count();

            $restaurantVotes = OriginVote::query()
                ->where('post_id', $post->id)
                ->where('origin', OriginType::Restaurant)
                ->count();

            $post->forceFill([
                'upvotes_count' => $upvotes,
                'downvotes_count' => $downvotes,
                'homemade_votes_count' => $homemadeVotes,
                'restaurant_votes_count' => $restaurantVotes,
            ])->save();

            $cuisineVotes = [];

            foreach (CuisineType::votable() as $cuisine) {
                $cuisineVotes[$cuisine->value] = CuisineVote::query()
                    ->where('post_id', $post->id)
                    ->where('cuisine', $cuisine->value)
                    ->count();
            }

            return new PostCounterSnapshot(
                upvotes: $upvotes,
                downvotes: $downvotes,
                homemadeVotes: $homemadeVotes,
                restaurantVotes: $restaurantVotes,
                cuisineVotes: $cuisineVotes,
            );
        });
    }
}
