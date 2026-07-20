<?php

declare(strict_types=1);

namespace App\Policies\ArchitectureFixtures;

use App\Models\Post;

final class MutatingPolicy
{
    public function update(Post $post): bool
    {
        return $post->update(['title' => 'changed']);
    }
}
