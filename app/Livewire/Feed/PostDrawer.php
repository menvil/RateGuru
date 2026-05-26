<?php

namespace App\Livewire\Feed;

use App\Actions\Posts\DeletePostAction;
use App\Enums\CuisineType;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\CuisineVote;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class PostDrawer extends Component
{
    public ?int $postId = null;

    public ?string $deleteError = null;

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

    #[On('cuisine-voted')]
    public function refreshAfterCuisineVote(): void
    {
        // Triggers a re-render so the drawer cuisine summary reflects fresh counters.
    }

    public function deleteSelectedPost(DeletePostAction $deletePostAction): void
    {
        if (! auth()->check() || $this->postId === null) {
            return;
        }

        $post = Post::query()->find($this->postId);

        if ($post === null) {
            $this->dispatch('clear-selected-post');

            return;
        }

        try {
            $deletePostAction->handle(auth()->user(), $post);
        } catch (CannotDeletePostException $e) {
            $this->deleteError = $e->getMessage();

            return;
        }

        $this->postId = null;
        $this->dispatch('clear-selected-post');
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
            'originDistribution' => $post ? $this->originDistribution($post) : null,
            'cuisineDistribution' => $post ? $this->cuisineDistribution($post) : null,
            'showSharePanel' => $post?->status === PostStatus::Published,
        ]);
    }

    /**
     * @return array{homemade:int,restaurant:int,homemadePct:int,restaurantPct:int,total:int,current:?string}
     */
    private function originDistribution(Post $post): array
    {
        $homemade = (int) ($post->homemade_votes_count ?? 0);
        $restaurant = (int) ($post->restaurant_votes_count ?? 0);
        $total = $homemade + $restaurant;
        $current = auth()->check()
            ? $post->originVotes()->where('user_id', auth()->id())->first()?->origin?->value
            : null;

        if ($current === null) {
            return [
                'homemade' => 0,
                'restaurant' => 0,
                'homemadePct' => 0,
                'restaurantPct' => 0,
                'total' => 0,
                'current' => null,
            ];
        }

        $homemadePct = $total > 0 ? (int) round(($homemade / $total) * 100) : 0;

        return [
            'homemade' => $homemade,
            'restaurant' => $restaurant,
            'homemadePct' => $homemadePct,
            'restaurantPct' => $total > 0 ? 100 - $homemadePct : 0,
            'total' => $total,
            'current' => $current,
        ];
    }

    /**
     * @return array{rows:list<array{label:string,count:int,percentage:int}>,total:int,current:?string}
     */
    private function cuisineDistribution(Post $post): array
    {
        $current = auth()->check()
            ? $post->cuisineVotes()->where('user_id', auth()->id())->first()?->cuisine?->value
            : null;

        $counts = $current === null
            ? collect()
            : CuisineVote::query()
                ->where('post_id', $post->id)
                ->selectRaw('cuisine, COUNT(*) as total')
                ->groupBy('cuisine')
                ->pluck('total', 'cuisine');

        $total = (int) $counts->sum();

        $rows = collect(CuisineType::votable())
            ->map(function (CuisineType $cuisine) use ($counts, $total): array {
                $count = (int) ($counts[$cuisine->value] ?? 0);

                return [
                    'label' => match ($cuisine) {
                        CuisineType::Italian => 'IT',
                        CuisineType::Asian => 'AS',
                        CuisineType::American => 'US',
                        CuisineType::Mexican => 'MX',
                        CuisineType::Other => 'OT',
                        CuisineType::Unknown => 'UN',
                    },
                    'count' => $count,
                    'percentage' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
                ];
            })
            ->all();

        return ['rows' => $rows, 'total' => $total, 'current' => $current];
    }
}
