<?php

use App\Models\Post;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Urls\PostUrl;

if (! function_exists('canonical_post_url')) {
    function canonical_post_url(Post $post): string
    {
        return app(PostUrl::class)->canonical($post);
    }
}

if (! function_exists('site_name')) {
    /** The project's name from its settings, in the current language: what people are told the site is called. */
    function site_name(): string
    {
        return app(ProjectSettingsManager::class)->current()->siteName();
    }
}
