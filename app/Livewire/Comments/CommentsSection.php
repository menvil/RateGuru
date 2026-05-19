<?php

namespace App\Livewire\Comments;

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Enums\CommentStatus;
use App\Models\Comment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class CommentsSection extends Component
{
    public int $postId;

    public bool $showHeader = true;

    #[Computed]
    public function comments(): Collection
    {
        return Comment::query()
            ->where('post_id', $this->postId)
            ->where('status', CommentStatus::Visible)
            ->with('user')
            ->oldest()
            ->get();
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
