<?php

namespace App\Livewire\Posts;

use App\Actions\Votes\VoteCuisineAction;
use App\Enums\CuisineType;
use App\Exceptions\Votes\CannotVoteCuisineException;
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

    public function render(): View
    {
        return view('livewire.posts.cuisine-voting', [
            'post' => $this->post,
            'options' => $this->options(),
        ]);
    }
}
