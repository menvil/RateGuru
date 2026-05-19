<?php

namespace App\Livewire\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
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

    public function render(): View
    {
        return view('livewire.comments.comments-section');
    }
}
