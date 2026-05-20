<?php

namespace App\Livewire\Moderation;

use App\Actions\Moderation\ApprovePostAction;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class InlinePostModeration extends Component
{
    public int $postId;

    public ?string $reason = null;

    public ?string $error = null;

    public ?string $success = null;

    #[Computed]
    public function canModerate(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isModerator() || $user->isAdmin());
    }

    public function approve(ApprovePostAction $approvePostAction): void
    {
        $this->runModerationAction(function () use ($approvePostAction): void {
            $approvePostAction->handle(
                moderator: auth()->user(),
                post: $this->post(),
                reason: $this->normalizedReason(),
            );

            $this->success = 'Post approved.';
            $this->dispatch('post-moderated', postId: $this->postId, action: 'approved');
        });
    }

    public function hide(): void
    {
        // wired in RG-439
    }

    public function reject(): void
    {
        // wired in RG-440
    }

    public function restore(): void
    {
        // wired in RG-441
    }

    public function render(): View
    {
        return view('livewire.moderation.inline-post-moderation', [
            'post' => $this->post(),
        ]);
    }

    private function post(): Post
    {
        return Post::query()->findOrFail($this->postId);
    }

    private function normalizedReason(): ?string
    {
        $reason = trim((string) $this->reason);

        return $reason === '' ? null : $reason;
    }

    private function runModerationAction(Closure $callback): void
    {
        $this->error = null;
        $this->success = null;

        if (! $this->canModerate()) {
            $this->error = 'You are not allowed to moderate posts.';

            return;
        }

        try {
            $callback();
        } catch (CannotModeratePostException $e) {
            $this->error = $e->getMessage();
            $this->success = null;
        }
    }
}
