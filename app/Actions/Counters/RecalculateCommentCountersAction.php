<?php

namespace App\Actions\Counters;

use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use Illuminate\Support\Facades\DB;

final class RecalculateCommentCountersAction
{
    /**
     * @return array{upvotes:int,downvotes:int}
     */
    public function handle(Comment $comment): array
    {
        return DB::transaction(function () use ($comment): array {
            $lockedComment = Comment::query()
                ->whereKey($comment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $upvotes = CommentVote::query()
                ->where('comment_id', $lockedComment->id)
                ->where('type', VoteType::Up)
                ->count();

            $downvotes = CommentVote::query()
                ->where('comment_id', $lockedComment->id)
                ->where('type', VoteType::Down)
                ->count();

            $lockedComment->forceFill([
                'upvotes_count' => $upvotes,
                'downvotes_count' => $downvotes,
            ])->save();

            return ['upvotes' => $upvotes, 'downvotes' => $downvotes];
        });
    }
}
