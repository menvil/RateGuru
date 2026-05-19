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
        $comment = Comment::query()
            ->where('post_id', $this->postId)
            ->findOrFail($commentId);

        $deleteCommentAction->handle(auth()->user(), $comment);

        unset($this->comments);

        $this->dispatch('comment-deleted', postId: $this->postId, commentId: $commentId);
    }

    public function hideComment(int $commentId, HideCommentAction $hideCommentAction): void
    {
        $comment = Comment::query()
            ->where('post_id', $this->postId)
            ->findOrFail($commentId);

        $hideCommentAction->handle(auth()->user(), $comment);

        unset($this->comments);

        $this->dispatch('comment-hidden', postId: $this->postId, commentId: $commentId);
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
