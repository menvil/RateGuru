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

            $counts = CommentVote::query()
                ->where('comment_id', $lockedComment->id)
                ->selectRaw('type, COUNT(*) as total')
                ->groupBy('type')
                ->get()
                ->mapWithKeys(fn (CommentVote $vote): array => [$vote->type->value => (int) $vote->total]);

            $upvotes = (int) ($counts[VoteType::Up->value] ?? 0);
            $downvotes = (int) ($counts[VoteType::Down->value] ?? 0);

            $lockedComment->forceFill([
                'upvotes_count' => $upvotes,
                'downvotes_count' => $downvotes,
            ])->save();

            return ['upvotes' => $upvotes, 'downvotes' => $downvotes];
        });
    }
}
