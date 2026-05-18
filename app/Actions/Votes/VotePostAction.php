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

            $this->incrementCounter($post, $type);
        });
    }

    private function incrementCounter(Post $post, VoteType $type): void
    {
        $post->increment($this->counterColumn($type));
    }

    private function counterColumn(VoteType $type): string
    {
        return $type === VoteType::Up ? 'upvotes_count' : 'downvotes_count';
    }
}
