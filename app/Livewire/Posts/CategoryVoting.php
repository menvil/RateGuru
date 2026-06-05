<?php

namespace App\Livewire\Posts;

final class CategoryVoting extends CuisineVoting
{
    protected string $viewName = 'livewire.posts.category-voting';

    protected string $votedEventName = 'category-voted';
}
