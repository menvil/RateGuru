<?php

namespace App\Livewire\Comments;

use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Livewire\Concerns\OpensAuthModal;
use App\Services\Comments\CommentVotingService;
use App\Support\Observability\GuestVoteAttemptLog;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class CommentVoting extends Component
{
    use OpensAuthModal;

    public int $commentId;

    public string $error = '';

    /**
     * What the thread already read for every comment it shows — the score,
     * the viewer's vote, whether it is the viewer's own — so the first render
     * asks nothing of its own. Dropped once a vote changes it.
     */
    #[Locked]
    public bool $hasPreloadedState = false;

    #[Locked]
    public int $preloadedScore = 0;

    #[Locked]
    public ?string $preloadedVote = null;

    #[Locked]
    public bool $preloadedOwn = false;

    #[Locked]
    public bool $preloadedMayVote = false;

    public function vote(string $type, CommentVotingService $commentVotingService, GuestVoteAttemptLog $guestVoteAttempts): void
    {
        // No vote on a comment while comments are switched off, from a thread
        // drawn before they were: the section around it refuses its own
        // requests, and this is a request of its own.
        abort_unless(app(ProjectSettingsManager::class)->featureEnabled('show_comments'), 403);

        $this->error = '';
        // From here on the comment as it is: a refusal below — the comment gone —
        // is drawn beside the buttons it now has.
        $this->hasPreloadedState = false;

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

            if ($e->isGuest()) {
                $this->openAuthModal();
            }

            return;
        }

        $this->dispatch('comment-voted', commentId: $this->commentId);
    }

    public function render(CommentVotingService $commentVotingService): View
    {
        if ($this->hasPreloadedState) {
            return $this->voting(true, $this->preloadedScore, $this->preloadedVote, $this->preloadedOwn, $this->preloadedMayVote);
        }

        $comment = $commentVotingService->comment($this->commentId);

        return $this->voting(
            $comment !== null,
            (int) $comment?->score,
            $comment !== null ? $commentVotingService->currentVote(auth()->user(), $comment->id)?->value : null,
            $comment !== null && auth()->check() && (int) $comment->user_id === (int) auth()->id(),
            $comment !== null && (auth()->user()?->can('vote', $comment) ?? false),
        );
    }

    private function voting(bool $available, int $score, ?string $currentVote, bool $own, bool $mayVote): View
    {
        return view('livewire.comments.comment-voting', [
            'available' => $available,
            'currentVote' => $currentVote,
            'isOwnComment' => $own,
            'upActive' => $currentVote === VoteType::Up->value,
            'downActive' => $currentVote === VoteType::Down->value,
            // Off where CommentPolicy::vote refuses: one's own comment, or an
            // account that has lost voting, whose every vote would be refused.
            // A guest is asked to sign in instead.
            'votingDisabled' => $available && auth()->check() && ! $mayVote,
            'score' => $score,
        ]);
    }
}
