<?php

namespace App\Livewire\Comments;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

final class CommentsSection extends Component
{
    public int $postId;

    public function render(): View
    {
        return view('livewire.comments.comments-section', [
            'comments' => collect(),
        ]);
    }
}
