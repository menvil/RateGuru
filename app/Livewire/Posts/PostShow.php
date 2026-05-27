<?php

namespace App\Livewire\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Support\Seo\PostOpenGraph;
use App\Support\View\AppLayoutData;
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
        $post = $this->post;
        $openGraph = app(PostOpenGraph::class);

        return view('livewire.posts.post-show', [
            'ogDescription' => $openGraph->description($post),
            'ogImage' => $openGraph->image($post),
            'ogTitle' => $openGraph->title($post),
            'post' => $post,
            'showSharePanel' => $post->status === PostStatus::Published,
        ])->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
