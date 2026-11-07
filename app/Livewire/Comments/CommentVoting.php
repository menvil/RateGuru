<?php

namespace App\Livewire\Comments;

use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Services\Comments\CommentVotingService;
use App\Support\Observability\GuestVoteAttemptLog;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class CommentVoting extends Component
{
    public int $commentId;

    public string $error = '';

    public function vote(string $type, CommentVotingService $commentVotingService, GuestVoteAttemptLog $guestVoteAttempts): void
    {
        // No vote on a comment while comments are switched off, from a thread
        // drawn before they were: the section around it refuses its own
        // requests, and this is a request of its own.
        abort_unless(app(ProjectSettingsManager::class)->featureEnabled('show_comments'), 403);

        $this->error = '';

        $voteType = VoteType::tryFrom($type);

        if ($voteType === null) {
            return;
        }

        // Refused below, by VoteCommentAction, as before; only recorded here.
        if (auth()->guest()) {
            $guestVoteAttempts->record('comment', ['comment_id' => $this->commentId, 'vote' => $voteType->value]);
        }

        $comment = $commentVotingService->comment($this->commentId);

        if ($comment === null) {
            $this->error = __('ui.comments.unavailable');

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
