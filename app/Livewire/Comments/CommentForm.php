<?php

namespace App\Livewire\Comments;

use App\Actions\Comments\AddCommentAction;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class CommentForm extends Component
{
    public int $postId;

    public string $body = '';

    public function submit(AddCommentAction $addCommentAction): void
    {
        $post = Post::query()->published()->find($this->postId);

        if ($post === null) {
            $this->addError('body', 'This post is no longer available.');

            return;
        }

        try {
            $comment = $addCommentAction->handle(
                user: auth()->user(),
                post: $post,
                body: $this->body,
            );
        } catch (CannotCommentException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        $this->reset('body');

        $this->dispatch('comment-created', postId: $this->postId, commentId: $comment->id);
    }

    public function render(): View
    {
        return view('livewire.comments.comment-form');
    }
}
