<?php

namespace App\Livewire\Posts;

use Illuminate\Contracts\View\View;

final class CategoryVoting extends CuisineVoting
{
    public function render(): View
    {
        $post = $this->post;
        $currentCuisine = null;

        if ($post !== null && auth()->check()) {
            $currentCuisine = $post->cuisineVotes()
                ->where('user_id', auth()->id())
                ->latest('id')
                ->first()
                ?->cuisine
                ?->value;
        }

        return view('livewire.posts.category-voting', [
            'post' => $post,
            'options' => $this->options(),
            'currentCuisine' => $currentCuisine,
            'isOwnPost' => $post !== null && auth()->check() && (int) $post->user_id === (int) auth()->id(),
            'hasVoted' => $currentCuisine !== null,
            'votingDisabled' => $currentCuisine !== null,
        ]);
    }
}
