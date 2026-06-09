<?php

namespace App\Livewire\Feed;

use App\Queries\Feed\FeedQuery;
use App\Support\Rating\RatingVotingStateLoader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class PostFeed extends Component
{
    use WithPagination;

    public ?string $search = null;

    public ?string $tag = null;

    public mixed $origin = [];

    public mixed $cuisine = [];

    public string $sort = 'newest';

    public ?int $selectedPostId = null;

    #[On('post-uploaded')]
    public function refreshAfterUpload(): void {}

    #[On('post-moderated')]
    public function refreshAfterPostModerated(): void {}

    public function render(
        FeedQuery $feedQuery,
        RatingVotingStateLoader $ratingVotingStateLoader,
    ): View {
        $paginator = $feedQuery->paginate(
            search: $this->search !== '' ? $this->search : null,
            tag: $this->tag !== '' ? $this->tag : null,
            sort: $this->sort,
            origin: $this->origin,
            cuisine: $this->cuisine,
        )->onEachSide(1);
        $posts = $paginator->getCollection();
        $user = auth()->user();

        return view('livewire.feed.post-feed', [
            'posts' => $posts,
            'paginator' => $paginator,
            'selectedPostId' => $this->selectedPostId,
            'ratingVotingStates' => $ratingVotingStateLoader->forPosts($posts, $user),
            'deletePermissions' => $posts
                ->mapWithKeys(fn ($post): array => [(int) $post->id => $user?->can('deleteFromFeed', $post) ?? false])
                ->all(),
            'reportPermissions' => $posts
                ->mapWithKeys(fn ($post): array => [(int) $post->id => $user?->can('report', $post) ?? false])
                ->all(),
            'moderationPermissions' => $posts
                ->mapWithKeys(fn ($post): array => [(int) $post->id => $user !== null && ($user->isModerator() || $user->isAdmin())])
                ->all(),
        ]);
    }
}
