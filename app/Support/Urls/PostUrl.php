<?php

namespace App\Support\Urls;

use App\Models\Post;

final class PostUrl
{
    public function canonical(Post $post): string
    {
        return rtrim((string) config('app.url'), '/').route('posts.show', $post, absolute: false);
    }
}
