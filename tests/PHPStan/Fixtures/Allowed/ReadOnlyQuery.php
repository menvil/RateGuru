<?php

declare(strict_types=1);

namespace App\Queries\ArchitectureFixtures;

use App\Models\Post;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;

final class ReadOnlyQuery
{
    /** @return Collection<int, Post> */
    public function get(): Collection
    {
        return Post::query()->where('status', 'published')->with('user')->get();
    }

    public function connectionName(ConnectionInterface $connection): string
    {
        return $connection->getDatabaseName();
    }
}
