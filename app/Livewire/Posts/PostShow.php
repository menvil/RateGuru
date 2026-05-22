<?php

namespace App\Livewire\Posts;

use App\Models\Post;
use App\Support\Seo\PostOpenGraph;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class PostShow extends Component
{
    public int $postId;

    public function mount(Post $post): void
    {
        $this->postId = $post->id;
    }

    public function getPostProperty(): Post
    {
        return Post::query()
            ->published()
            ->with(['user', 'tags'])
            ->findOrFail($this->postId);
    }

    #[On('post-voted')]
    public function refreshAfterVote(): void
    {
        // Triggers a re-render so the vote summary panel reflects fresh counters.
    }

    public function render(): View
    {
        return view('livewire.posts.post-show', [
            'ogImage' => app(PostOpenGraph::class)->image($this->post),
            'post' => $this->post,
        ]);
    }
}
