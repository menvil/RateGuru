<?php

namespace App\View\Components\Comments;

use App\Models\Comment;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class CommentItem extends Component
{
    public bool $canReport;

    public bool $hasMenuActions;

    public function __construct(
        public Comment $comment,
        public bool $canDelete = false,
        public bool $canHide = false,
        public bool $canReply = false,
    ) {
        $this->canReport = $comment->exists && auth()->check() && (int) auth()->id() !== (int) $comment->user_id;
        $this->hasMenuActions = $this->canDelete || $this->canHide || $this->canReport;
    }

    public function render(): View
    {
        return view('components.comments.comment-item');
    }
}
