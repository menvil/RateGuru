<?php

namespace App\Livewire\Posts;

use App\Enums\CuisineType;
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

    public function render(): View
    {
        return view('livewire.posts.cuisine-voting', [
            'post' => $this->post,
            'options' => $this->options(),
        ]);
    }
}
