<?php

namespace App\Livewire\Moderation;

use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\RestorePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Support\Moderation\ModerationReason;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class InlinePostModeration extends Component
{
    /** The longest reason kept, as the admin's moderation dialogs take. */
    public const REASON_LIMIT = 1000;

    public int $postId;

    public ?string $reason = null;

    public ?string $error = null;

    public ?string $success = null;

    public string $variant = 'panel';

    private ?Post $cachedPost = null;

    #[Computed]
    public function canModerate(): bool
    {
        return Gate::allows('moderate-content');
    }

    #[Computed]
    public function adminPostUrl(): ?string
    {
        if (Route::has('filament.admin.resources.posts.edit')) {
            return route('filament.admin.resources.posts.edit', ['record' => $this->postId]);
        }

        return null;
    }

    #[Computed]
    public function canHide(): bool
    {
        return $this->canModerate()
            && $this->post()->status === PostStatus::Published;
    }

    #[Computed]
    public function canRestore(): bool
    {
        // No Restore the action would refuse: the same rule, on this copy.
        return $this->canModerate() && $this->post()->canBeRestoredByModeration();
    }

    public function hide(HidePostAction $hidePostAction): void
    {
        $this->runModerationAction(function () use ($hidePostAction): void {
            $hidePostAction->handle(
                moderator: auth()->user(),
                post: $this->post(),
                reason: $this->normalizedReason(),
            );

            $this->success = __('ui.moderation.hidden');
            $this->dispatch('post-moderated', postId: $this->postId, action: 'hidden');
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

            $this->success = __('ui.moderation.restored');
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
        return $this->cachedPost ??= Post::query()->findOrFail($this->postId);
    }

    /** As the moderation log stores it, invisible edges taken off too. */
    private function normalizedReason(): ?string
    {
        $reason = ModerationReason::normalize((string) $this->reason);

        return $reason === '' ? null : $reason;
    }

    private function runModerationAction(Closure $callback): void
    {
        $this->error = null;
        $this->success = null;

        if (! $this->canModerate()) {
            $this->error = __('ui.moderation.not_allowed');

            return;
        }

        // Held to its limit here too, not by the field's maxlength alone, and
        // as the action receives it, trimmed. Said where either form shows
        // what went wrong: the menu has no field to show it beside.
        $reason = $this->normalizedReason();

        if ($reason !== null && mb_strlen($reason) > self::REASON_LIMIT) {
            $this->error = __('validation.max.string', ['attribute' => __('ui.moderation.reason'), 'max' => self::REASON_LIMIT]);

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
