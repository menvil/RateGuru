<?php

declare(strict_types=1);

namespace App\Http\Controllers\ArchitectureFixtures;

use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;

final class EloquentReads
{
    /** @return Collection<int, Post> */
    public function index(): Collection
    {
        return Post::query()->where('status', 'published')->get();
    }

    public function load(Post $post): Post
    {
        return $post->loadMissing('user');
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return Collection<int, Post>
     */
    public function loadAll(Collection $posts): Collection
    {
        return $posts->load('user');
    }

    public function relation(Post $post): Collection
    {
        return $post->comments()->latest()->get();
    }
}
