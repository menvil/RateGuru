<?php

namespace App\Services\Comments;

use App\Actions\Votes\VoteCommentAction;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\User;

final class CommentVotingService
{
    public function __construct(
        private readonly VoteCommentAction $voteCommentAction,
    ) {}

    public function comment(int $commentId): ?Comment
    {
        return Comment::query()
            ->whereNull('deleted_at')
            ->find($commentId);
    }

    public function currentVote(?User $user, int $commentId): ?VoteType
    {
        if ($user === null) {
            return null;
        }

        return CommentVote::query()
            ->where('comment_id', $commentId)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first()
            ?->type;
    }

    public function vote(?User $user, int $commentId, VoteType $type): ?Comment
    {
        $comment = $this->comment($commentId);

        if ($comment === null) {
            return null;
        }

        $this->voteCommentAction->handle($user, $comment, $type);

        return $comment->refresh();
    }
}
