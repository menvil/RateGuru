<?php

declare(strict_types=1);

namespace App\Queries\ArchitectureFixtures;

use App\Models\Post;
use Illuminate\Support\Facades\DB;

final class MutatingQuery
{
    public function save(Post $post): void
    {
        $post->save();
    }

    public function update(): void
    {
        Post::query()->update(['title' => 'changed']);
    }

    public function sync(Post $post): void
    {
        $post->tags()->sync([]);
    }

    public function lock(): void
    {
        Post::query()->lockForUpdate()->first();
    }

    public function transaction(): void
    {
        DB::transaction(static fn (): null => null);
    }
}
