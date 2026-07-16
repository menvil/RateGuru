<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeletePostInAdminAction
{
    public function handle(User $admin, Post $post): void
    {
        Gate::forUser($admin)->authorize('delete', $post);

        $post->delete();
    }
}
