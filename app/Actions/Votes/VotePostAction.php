<?php

namespace App\Actions\Votes;

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VotePostAction
{
    public function handle(User $user, Post $post, VoteType $type): void
    {
        DB::transaction(function () use ($user, $post, $type) {
            PostVote::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'type' => $type,
            ]);

            if ($type === VoteType::Up) {
                $post->increment('upvotes_count');
            }

            if ($type === VoteType::Down) {
                $post->increment('downvotes_count');
            }
        });
    }
}
