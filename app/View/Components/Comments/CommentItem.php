<?php

namespace App\View\Components\Comments;

use App\Models\Comment;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class CommentItem extends Component
{
    public bool $canReport;

    public bool $hasMenuActions;

    public bool $isTombstone;

    public function __construct(
        public Comment $comment,
        public bool $canDelete = false,
        public bool $canHide = false,
        public bool $canReply = false,
    ) {
        $this->isTombstone = $comment->isStructuralTombstone();

        // A structural tombstone exists publicly only to anchor its
        // surviving replies: no identity, no body, and no actions of any
        // kind — the flags are forced off here so no caller can re-enable
        // them, mirroring the backend guards in the actions themselves.
        if ($this->isTombstone) {
            $this->canDelete = false;
            $this->canHide = false;
            $this->canReply = false;
            $this->canReport = false;
            $this->hasMenuActions = false;

            return;
        }

        $this->canReport = $comment->exists && auth()->check() && (int) auth()->id() !== (int) $comment->user_id;
        $this->hasMenuActions = $this->canDelete || $this->canHide || $this->canReport;
    }

    public function render(): View
    {
        return view('components.comments.comment-item');
    }
}
