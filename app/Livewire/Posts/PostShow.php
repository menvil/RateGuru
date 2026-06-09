<?php

namespace App\Livewire\Posts;

use App\Models\Post;
use App\Support\Rating\RatingConfigurationManager;
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

    #[On('origin-voted')]
    public function refreshAfterOriginVote(): void
    {
        // Triggers a re-render so the origin summary reflects fresh counters.
    }

    #[On('source-voted')]
    public function refreshAfterSourceVote(): void
    {
        $this->refreshAfterOriginVote();
    }

    #[On('cuisine-voted')]
    public function refreshAfterCuisineVote(): void
    {
        // Triggers a re-render after cuisine votes.
    }

    #[On('category-voted')]
    public function refreshAfterCategoryVote(): void
    {
        $this->refreshAfterCuisineVote();
    }

    public function render(RatingConfigurationManager $configuration): View
    {
        $post = $this->post;
        $openGraph = app(PostOpenGraph::class);

        return view('livewire.posts.post-show', [
            'ogDescription' => $openGraph->description($post),
            'ogImage' => $openGraph->image($post),
            'ogTitle' => $openGraph->title($post),
            'post' => $post,
            'activeRatingGroups' => $configuration->activeGroups(),
        ])->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
