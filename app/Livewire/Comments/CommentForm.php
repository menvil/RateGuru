<?php

namespace App\Livewire\Comments;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class CommentForm extends Component
{
    public int $postId;

    public string $body = '';

    public function render(): View
    {
        return view('livewire.comments.comment-form');
    }
}
