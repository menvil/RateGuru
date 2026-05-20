<?php

namespace App\Livewire\Moderation;

use App\Actions\Moderation\ApprovePostAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\RejectPostAction;
use App\Actions\Moderation\RestorePostAction;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
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

    #[Computed]
    public function adminPostUrl(): ?string
    {
        if (Route::has('filament.admin.resources.posts.edit')) {
            return route('filament.admin.resources.posts.edit', ['record' => $this->postId]);
        }

        return null;
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

    public function hide(HidePostAction $hidePostAction): void
    {
        $this->runModerationAction(function () use ($hidePostAction): void {
            $hidePostAction->handle(
                moderator: auth()->user(),
                post: $this->post(),
                reason: $this->normalizedReason(),
            );

            $this->success = 'Post hidden.';
            $this->dispatch('post-moderated', postId: $this->postId, action: 'hidden');
        });
    }

    public function reject(RejectPostAction $rejectPostAction): void
    {
        $this->runModerationAction(function () use ($rejectPostAction): void {
            $rejectPostAction->handle(
                moderator: auth()->user(),
                post: $this->post(),
                reason: $this->normalizedReason(),
            );

            $this->success = 'Post rejected.';
            $this->dispatch('post-moderated', postId: $this->postId, action: 'rejected');
        });
    }

    public function restore(RestorePostAction $restorePostAction): void
    {
        $this->runModerationAction(function () use ($restorePostAction): void {
            $restorePostAction->handle(
                moderator: auth()->user(),
                post: $this->post(),
                reason: $this->normalizedReason(),
            );

            $this->success = 'Post restored.';
            $this->dispatch('post-moderated', postId: $this->postId, action: 'restored');
        });
    }

    public function render(): View
    {
        // Skip the post lookup for viewers who cannot moderate: the panel
        // and every reference to $post in the view are guarded by
        // @if ($this->canModerate), so non-moderators do not need the row.
        return view('livewire.moderation.inline-post-moderation', [
            'post' => $this->canModerate() ? $this->post() : null,
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
