<?php

namespace App\Livewire\Feed;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class PostDrawer extends Component
{
    public ?int $postId = null;

    #[On('post-voted')]
    public function refreshAfterVote(): void
    {
        // Triggers a re-render so the drawer vote summary reflects fresh counters.
    }

    #[On('origin-voted')]
    public function refreshAfterOriginVote(): void
    {
        // Triggers a re-render so the drawer origin summary reflects fresh counters.
    }

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
            'showSharePanel' => $post?->status === PostStatus::Published,
        ]);
    }
}
