<?php

namespace App\Actions\Votes;

use App\Enums\CuisineType;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VoteCuisineAction
{
    public function handle(?User $user, Post $post, CuisineType $cuisine): void
    {
        DB::transaction(function () use ($user, $post, $cuisine) {
            CuisineVote::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'cuisine' => $cuisine,
            ]);
        });
    }
}
