<?php

namespace App\Livewire\Comments;

use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Services\Comments\CommentVotingService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class CommentVoting extends Component
{
    public int $commentId;

    public string $error = '';

    public function vote(string $type, CommentVotingService $commentVotingService): void
    {
        $this->error = '';

        $voteType = VoteType::tryFrom($type);

        if ($voteType === null) {
            return;
        }

        $comment = $commentVotingService->comment($this->commentId);

        if ($comment === null) {
            $this->error = 'This comment is no longer available.';

            return;
        }

        $currentVote = $commentVotingService->currentVote(auth()->user(), $comment->id);

        $voteToApply = $voteType;

        try {
            $commentVotingService->vote(auth()->user(), $comment->id, $voteToApply);
        } catch (CannotVoteCommentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->dispatch('comment-voted', commentId: $this->commentId);
    }

    #[On('comment-voted')]
    public function refreshAfterCommentVote(int $commentId): void
    {
        //
    }

    public function render(CommentVotingService $commentVotingService): View
    {
        $comment = $commentVotingService->comment($this->commentId);
        $currentVote = null;

        if ($comment !== null) {
            $currentVote = $commentVotingService->currentVote(auth()->user(), $comment->id)?->value;
        }

        return view('livewire.comments.comment-voting', [
            'comment' => $comment,
            'currentVote' => $currentVote,
            'isOwnComment' => $comment !== null && auth()->check() && (int) $comment->user_id === (int) auth()->id(),
            'upActive' => $currentVote === VoteType::Up->value,
            'downActive' => $currentVote === VoteType::Down->value,
            'votingDisabled' => $comment !== null && auth()->check() && (int) $comment->user_id === (int) auth()->id(),
            'score' => (int) $comment?->score,
        ]);
    }
}
