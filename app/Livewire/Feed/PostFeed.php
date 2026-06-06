<?php

namespace App\Livewire\Feed;

use App\Queries\Feed\FeedQuery;
use App\Services\PostVoteResultService;
use App\Support\Rating\RatingVotingStateLoader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class PostFeed extends Component
{
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
        PostVoteResultService $postVoteResultService,
        RatingVotingStateLoader $ratingVotingStateLoader,
    ): View {
        $posts = $feedQuery->get(
            search: $this->search !== '' ? $this->search : null,
            tag: $this->tag !== '' ? $this->tag : null,
            sort: $this->sort,
            origin: $this->origin,
            cuisine: $this->cuisine,
        );
        $user = auth()->user();

        return view('livewire.feed.post-feed', [
            'posts' => $posts,
            'selectedPostId' => $this->selectedPostId,
            'originDistributions' => $postVoteResultService->originDistributions($posts, $user),
            'cuisineDistributions' => $postVoteResultService->cuisineDistributions($posts, $user),
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
