<?php

namespace App\Livewire\Comments;

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Enums\VoteType;
use App\Exceptions\Comments\CannotCommentException;
use App\Exceptions\Comments\CannotDeleteCommentException;
use App\Exceptions\Comments\CannotHideCommentException;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Queries\Comments\CommentListQuery;
use App\Services\Comments\CommentReplyService;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** @property-read Collection<int, Comment> $comments */
final class CommentsSection extends Component
{
    /** Set once by the published post that mounts it, never by the client. */
    #[Locked]
    public int $postId;

    public bool $showHeader = true;

    /** Moves only by loadMore() and a new sort, never as the client sets it. */
    #[Locked]
    public int $visibleCount = 5;

    public string $commentSort = 'top';

    public ?int $replyingTo = null;

    public string $replyBody = '';

    /**
     * On every request, not only the first: a page drawn before comments were
     * switched off still has this section, and none of it — more comments, a
     * new order, a reply — goes on while they are off. Every page that draws
     * it does so only while they are on.
     */
    public function boot(): void
    {
        abort_unless(app(ProjectSettingsManager::class)->featureEnabled('show_comments'), 403);
    }

    #[Computed]
    public function comments(): Collection
    {
        return app(CommentListQuery::class)->get(
            $this->postId,
            $this->commentSort,
            $this->visibleCount,
        );
    }

    #[Computed]
    public function totalComments(): int
    {
        return app(CommentListQuery::class)->countVisible($this->postId);
    }

    #[Computed]
    public function totalRenderableTopLevel(): int
    {
        // As many as the list can show: past the cap, "View more" would load nothing more.
        return min(app(CommentListQuery::class)->countRenderableTopLevel($this->postId), CommentListQuery::MOST_ROOTS);
    }

    /**
     * The viewer's vote on every comment the thread shows, replies included,
     * read once for all of them.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function viewerVotes(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $ids = $this->comments->flatMap(fn (Comment $comment): array => [$comment->id, ...$comment->replies->modelKeys()])->all();

        return CommentVote::query()
            ->where('user_id', $user->id)
            ->whereIn('comment_id', $ids)
            ->pluck('type', 'comment_id')
            ->map(fn (VoteType|string $type): string => $type instanceof VoteType ? $type->value : $type)
            ->all();
    }

    /** @return array{score: int, vote: ?string, own: bool, mayVote: bool} */
    public function votingStateFor(Comment $comment): array
    {
        return [
            'score' => (int) $comment->score,
            'vote' => $this->viewerVotes[$comment->id] ?? null,
            'own' => auth()->check() && (int) $comment->user_id === (int) auth()->id(),
            // The policy's answer, from the account and the comment in hand.
            'mayVote' => auth()->user()?->can('vote', $comment) ?? false,
        ];
    }

    public function loadMore(): void
    {
        $this->visibleCount += 5;

        unset($this->comments);
    }

    public function setCommentSort(string $sort): void
    {
        if (! in_array($sort, ['top', 'newest', 'hot'], true)) {
            return;
        }

        $this->commentSort = $sort;
        $this->visibleCount = 5;

        unset($this->comments);
    }

    public function startReply(int $commentId, CommentReplyService $commentReplyService): void
    {
        if (! $commentReplyService->canStartReply($this->postId, $commentId)) {
            return;
        }

        $this->replyingTo = $commentId;
        $this->replyBody = '';
        $this->resetErrorBag('replyBody');
    }

    public function cancelReply(): void
    {
        $this->replyingTo = null;
        $this->replyBody = '';
        $this->resetErrorBag('replyBody');
    }

    public function submitReply(CommentReplyService $commentReplyService): void
    {
        if ($this->replyingTo === null) {
            return;
        }

        try {
            $comment = $commentReplyService->createReply(
                user: auth()->user(),
                postId: $this->postId,
                parentCommentId: $this->replyingTo,
                body: $this->replyBody,
            );
        } catch (CannotCommentException $e) {
            $this->addError(
                'replyBody',
                match (true) {
                    $e->isGuest() => __('ui.comments.sign_in_to_reply'),
                    $e->isNotAllowed() && auth()->user()?->needsAddressConfirmation() => __('ui.account_confirmation.required'),
                    default => $e->getMessage(),
                },
            );

            return;
        }

        $this->cancelReply();

        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalRenderableTopLevel);

        $this->dispatch('comment-created', postId: $this->postId, commentId: $comment->id);
    }

    #[On('comment-created')]
    public function onCommentCreated(int $postId, ?int $commentId = null): void
    {
        if ($postId !== $this->postId) {
            return;
        }

        // Surface the freshly posted comment: under "top"/"hot" sorting a new
        // comment with zero votes lands at the bottom of the list, invisible
        // to its author.
        $isTopLevel = $commentId !== null && Comment::query()
            ->whereKey($commentId)
            ->whereNull('parent_id')
            ->exists();

        if ($isTopLevel && $this->commentSort !== 'newest') {
            $this->commentSort = 'newest';
        }

        $this->refreshComments($postId);
    }

    #[On('comment-deleted')]
    #[On('comment-hidden')]
    public function refreshComments(int $postId, ?int $commentId = null): void
    {
        if ($postId !== $this->postId) {
            return;
        }

        if ($commentId !== null) {
            $this->forgetReplyTo($commentId);
        }

        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalRenderableTopLevel);
    }

    public function deleteComment(int $commentId, DeleteCommentAction $deleteCommentAction): void
    {
        // The delete button never renders for guests, but Livewire action
        // methods are publicly invocable. DeleteCommentAction::handle() takes a
        // non-nullable User, so a guest-crafted request would TypeError (500)
        // instead of being cleanly denied.
        if (! auth()->check()) {
            return;
        }

        // Deleted in another tab meanwhile: said as any other change meanwhile
        // is, not answered with a 404.
        $comment = Comment::query()
            ->where('post_id', $this->postId)
            ->find($commentId);

        if ($comment === null) {
            $this->refusedMeanwhile();

            return;
        }

        try {
            $deleteCommentAction->handle(auth()->user(), $comment);
        } catch (CannotDeleteCommentException) {
            $this->refusedMeanwhile();

            return;
        }

        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalRenderableTopLevel);
        $this->forgetReplyTo($commentId);

        $this->dispatch('comment-deleted', postId: $this->postId, commentId: $commentId);
    }

    public function hideComment(int $commentId, HideCommentAction $hideCommentAction): void
    {
        // Same guard as deleteComment: HideCommentAction::handle() takes a
        // non-nullable User, so an unauthenticated invocation would TypeError
        // (500) rather than being denied.
        if (! auth()->check()) {
            return;
        }

        // Deleted in another tab meanwhile: said as any other change meanwhile
        // is, not answered with a 404.
        $comment = Comment::query()
            ->where('post_id', $this->postId)
            ->find($commentId);

        if ($comment === null) {
            $this->refusedMeanwhile();

            return;
        }

        try {
            // False: hidden by someone else since this thread was drawn. Said
            // as the moderation list says it, the thread drawn as it is now.
            if (! $hideCommentAction->handle(auth()->user(), $comment)) {
                $this->forgetReplyTo($commentId);
                $this->refusedMeanwhile();

                return;
            }
        } catch (CannotHideCommentException) {
            $this->refusedMeanwhile();

            return;
        }

        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalRenderableTopLevel);
        $this->forgetReplyTo($commentId);

        $this->dispatch('comment-hidden', postId: $this->postId, commentId: $commentId);
    }

    /** A reply under a comment that is gone now has nothing to answer: its form closes. */
    private function forgetReplyTo(int $commentId): void
    {
        if ($this->replyingTo === $commentId) {
            $this->cancelReply();
        }
    }

    /**
     * The action re-reads the comment and the account under lock, so it can
     * refuse what the page still offered: the comment was hidden or deleted
     * meanwhile, or the account lost the right. The thread is drawn again as
     * it is now, with a word about it rather than an error page.
     */
    private function refusedMeanwhile(): void
    {
        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalRenderableTopLevel);

        $this->dispatch('toast', message: __('ui.comments.unavailable'));
    }

    public function canDeleteComment(Comment $comment): bool
    {
        // Delegate to CommentPolicy::delete() (the same authorization
        // DeleteCommentAction enforces) so the rule lives in one place.
        return auth()->user()?->can('delete', $comment) ?? false;
    }

    public function userCanHideComments(): bool
    {
        return Gate::allows('moderate-content');
    }

    public function render(): View
    {
        return view('livewire.comments.comments-section');
    }
}
