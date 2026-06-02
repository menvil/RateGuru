<?php

namespace App\Livewire\Comments;

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Enums\CommentStatus;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
use App\Services\Comments\CommentReplyService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class CommentsSection extends Component
{
    public int $postId;

    public bool $showHeader = true;

    public int $visibleCount = 5;

    public string $commentSort = 'top';

    public ?int $replyingTo = null;

    public string $replyBody = '';

    #[Computed]
    public function comments(): Collection
    {
        return Comment::query()
            ->where('post_id', $this->postId)
            ->where('status', CommentStatus::Visible)
            ->whereNull('parent_id')
            ->with([
                'user',
                'replies' => fn ($query) => $query
                    ->where('status', CommentStatus::Visible)
                    ->with('user')
                    ->oldest(),
            ])
            ->when($this->commentSort === 'newest', fn ($query) => $query->latest())
            ->when($this->commentSort === 'top', fn ($query) => $query
                ->orderByRaw('(upvotes_count - downvotes_count) DESC')
                ->latest())
            ->when($this->commentSort === 'hot', fn ($query) => $query
                ->orderByRaw('(upvotes_count + downvotes_count) DESC')
                ->latest())
            ->limit($this->visibleCount)
            ->get();
    }

    #[Computed]
    public function totalComments(): int
    {
        return Comment::query()
            ->where('post_id', $this->postId)
            ->where('status', CommentStatus::Visible)
            ->count();
    }

    #[Computed]
    public function totalTopLevelComments(): int
    {
        return Comment::query()
            ->where('post_id', $this->postId)
            ->where('status', CommentStatus::Visible)
            ->whereNull('parent_id')
            ->count();
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
                $e->isGuest()
                    ? 'You must be signed in to reply.'
                    : $e->getMessage(),
            );

            return;
        }

        $this->cancelReply();

        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalTopLevelComments);

        $this->dispatch('comment-created', postId: $this->postId, commentId: $comment->id);
    }

    #[On('comment-created')]
    #[On('comment-deleted')]
    #[On('comment-hidden')]
    public function refreshComments(int $postId): void
    {
        if ($postId !== $this->postId) {
            return;
        }

        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalTopLevelComments);
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

        $comment = Comment::query()
            ->where('post_id', $this->postId)
            ->findOrFail($commentId);

        $deleteCommentAction->handle(auth()->user(), $comment);

        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalTopLevelComments);

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

        $comment = Comment::query()
            ->where('post_id', $this->postId)
            ->findOrFail($commentId);

        $hideCommentAction->handle(auth()->user(), $comment);

        unset($this->comments);
        unset($this->totalComments);
        unset($this->totalTopLevelComments);

        $this->dispatch('comment-hidden', postId: $this->postId, commentId: $commentId);
    }

    public function canDeleteComment(Comment $comment): bool
    {
        // Delegate to CommentPolicy::delete() (the same authorization
        // DeleteCommentAction enforces) so the rule lives in one place.
        return auth()->user()?->can('delete', $comment) ?? false;
    }

    public function userCanHideComments(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isModerator() || $user->isAdmin());
    }

    public function render(): View
    {
        return view('livewire.comments.comments-section');
    }
}
