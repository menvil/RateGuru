<?php

namespace App\Livewire\Posts;

use App\Actions\Votes\VoteCuisineAction;
use App\Enums\CuisineType;
use App\Exceptions\Votes\CannotVoteCuisineException;
use App\Models\CuisineVote;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class CuisineVoting extends Component
{
    public int $postId;

    public string $error = '';

    /**
     * @return list<CuisineType>
     */
    private function options(): array
    {
        return [
            CuisineType::Italian,
            CuisineType::Asian,
            CuisineType::American,
            CuisineType::Mexican,
            CuisineType::Other,
        ];
    }

    public function labelFor(CuisineType $cuisine): string
    {
        return match ($cuisine) {
            CuisineType::Italian => 'Italian',
            CuisineType::Asian => 'Asian',
            CuisineType::American => 'American',
            CuisineType::Mexican => 'Mexican',
            CuisineType::Other => 'Other',
            CuisineType::Unknown => 'Unknown',
        };
    }

    public function getPostProperty(): ?Post
    {
        return Post::query()
            ->published()
            ->find($this->postId);
    }

    public function vote(string $cuisine, VoteCuisineAction $voteCuisineAction): void
    {
        $this->error = '';

        $cuisineType = CuisineType::tryFrom($cuisine);

        if ($cuisineType === null) {
            return;
        }

        $post = $this->post;

        if ($post === null) {
            $this->error = 'This post is no longer available.';

            return;
        }

        try {
            $voteCuisineAction->handle(auth()->user(), $post, $cuisineType);
        } catch (CannotVoteCuisineException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->dispatch('cuisine-voted', postId: $this->postId);
    }

    /**
     * Distribution is aggregated from cuisine_votes — there are no
     * persisted cuisine counter columns on posts (Phase 15 constraint).
     *
     * @return array{rows:list<array{cuisine:CuisineType,label:string,count:int,percentage:int}>,total:int}
     */
    public function getDistributionProperty(): array
    {
        $counts = CuisineVote::query()
            ->where('post_id', $this->postId)
            ->selectRaw('cuisine, COUNT(*) as total')
            ->groupBy('cuisine')
            ->pluck('total', 'cuisine');

        $total = (int) $counts->sum();

        $rows = collect($this->options())
            ->map(function (CuisineType $cuisine) use ($counts, $total) {
                $count = (int) ($counts[$cuisine->value] ?? 0);

                return [
                    'cuisine' => $cuisine,
                    'label' => $this->labelFor($cuisine),
                    'count' => $count,
                    'percentage' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
                ];
            })
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function render(): View
    {
        return view('livewire.posts.cuisine-voting', [
            'post' => $this->post,
            'options' => $this->options(),
        ]);
    }
}
