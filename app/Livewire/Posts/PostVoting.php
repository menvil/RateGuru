<?php

namespace App\Livewire\Posts;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class PostVoting extends Component
{
    public int $postId;

    public string $error = '';

    public function getPostProperty(): Post
    {
        return Post::query()
            ->published()
            ->findOrFail($this->postId);
    }

    public function render(): View
    {
        return view('livewire.posts.post-voting', [
            'post' => $this->post,
        ]);
    }
}
