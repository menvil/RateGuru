<?php

use App\Models\Post;
use App\Support\Urls\PostUrl;

if (! function_exists('canonical_post_url')) {
    function canonical_post_url(Post $post): string
    {
        return app(PostUrl::class)->canonical($post);
    }
}
