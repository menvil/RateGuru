<?php

namespace App\Livewire\Moderation;

use App\Models\Post;
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

    public function approve(): void
    {
        // wired in RG-438
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
            'post' => Post::query()->findOrFail($this->postId),
        ]);
    }
}
