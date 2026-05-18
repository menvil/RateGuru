<?php

namespace App\Actions\Votes;

use App\Enums\OriginType;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VoteOriginAction
{
    public function handle(?User $user, Post $post, OriginType $origin): void
    {
        DB::transaction(function () use ($user, $post, $origin) {
            OriginVote::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'origin' => $origin,
            ]);

            if ($origin === OriginType::Homemade) {
                $post->increment('homemade_votes_count');
            }
        });
    }
}
