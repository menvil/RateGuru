<?php

namespace App\Livewire\Comments;

use App\Actions\Votes\VoteCommentAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Models\Comment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class CommentVoting extends Component
{
    public int $commentId;

    public string $error = '';

    public function getCommentProperty(): ?Comment
    {
        return Comment::query()
            ->whereNull('deleted_at')
            ->find($this->commentId);
    }

    public function vote(string $type, VoteCommentAction $voteCommentAction): void
    {
        $this->error = '';

        $voteType = VoteType::tryFrom($type);

        if ($voteType === null) {
            return;
        }

        $comment = $this->comment;

        if ($comment === null) {
            $this->error = 'This comment is no longer available.';

            return;
        }

        $currentVote = $this->currentVoteFor($comment);

        if ($currentVote === $voteType) {
            return;
        }

        $voteToApply = $currentVote ?? $voteType;

        try {
            $voteCommentAction->handle(auth()->user(), $comment, $voteToApply);
        } catch (CannotVoteCommentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        unset($this->comment);

        $this->dispatch('comment-voted', commentId: $this->commentId);
    }

    #[On('comment-voted')]
    public function refreshAfterCommentVote(int $commentId): void
    {
        if ($commentId === $this->commentId) {
            unset($this->comment);
        }
    }

    public function render(): View
    {
        $comment = $this->comment;
        $currentVote = null;

        if ($comment !== null) {
            $currentVote = $this->currentVoteFor($comment)?->value;
        }

        return view('livewire.comments.comment-voting', [
            'comment' => $comment,
            'currentVote' => $currentVote,
            'isOwnComment' => $comment !== null && auth()->check() && (int) $comment->user_id === (int) auth()->id(),
        ]);
    }

    private function currentVoteFor(Comment $comment): ?VoteType
    {
        if (! auth()->check()) {
            return null;
        }

        return $comment->commentVotes()
            ->where('user_id', auth()->id())
            ->first()
            ?->type;
    }
}
