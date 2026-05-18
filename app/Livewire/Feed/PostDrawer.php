<?php

namespace App\Livewire\Feed;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class PostDrawer extends Component
{
    public ?int $postId = null;

    public function render(): View
    {
        $post = null;

        if ($this->postId !== null) {
            $post = Post::query()
                ->published()
                ->with(['user', 'tags'])
                ->find($this->postId);
        }

        return view('livewire.feed.post-drawer', [
            'post' => $post,
        ]);
    }
}
