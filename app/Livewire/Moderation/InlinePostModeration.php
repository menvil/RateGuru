<?php

namespace App\Livewire\Moderation;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class InlinePostModeration extends Component
{
    public int $postId;

    public ?string $reason = null;

    public ?string $error = null;

    public ?string $success = null;

    public function render(): View
    {
        return view('livewire.moderation.inline-post-moderation', [
            'post' => Post::query()->findOrFail($this->postId),
        ]);
    }
}
