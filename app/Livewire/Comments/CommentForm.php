<?php

namespace App\Livewire\Comments;

use App\Actions\Comments\AddCommentAction;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Post;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class CommentForm extends Component
{
    public int $postId;

    public string $body = '';

    public function submit(AddCommentAction $addCommentAction): void
    {
        // Comments switched off in Project settings take no new one, from a
        // page drawn before the switch included.
        abort_unless(app(ProjectSettingsManager::class)->featureEnabled('show_comments'), 403);

        $post = Post::query()->published()->find($this->postId);

        if ($post === null) {
            $this->addError('body', __('ui.post.unavailable'));

            return;
        }

        try {
            $comment = $addCommentAction->handle(
                user: auth()->user(),
                post: $post,
                body: $this->body,
            );
        } catch (CannotCommentException $e) {
            $this->addError(
                'body',
                match (true) {
                    $e->isGuest() => __('ui.comments.sign_in_to_comment'),
                    $e->isNotAllowed() && auth()->user()?->needsAddressConfirmation() => __('ui.account_confirmation.required'),
                    default => $e->getMessage(),
                },
            );

            return;
        }

        $this->reset('body');
        // The error an earlier attempt left belongs to that attempt.
        $this->resetErrorBag('body');

        $this->dispatch('comment-created', postId: $this->postId, commentId: $comment->id);
    }

    public function render(): View
    {
        return view('livewire.comments.comment-form');
    }
}
