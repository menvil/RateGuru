<?php

it('has all required saved posts translation keys in each locale', function (string $locale) {
    app()->setLocale($locale);

    $keys = [
        'save',
        'saved',
        'unsave',
        'save_post',
        'saved_posts',
        'page_title',
        'empty_title',
        'empty_description',
        'login_required',
        'feature_disabled',
        'post_unavailable',
    ];

    foreach ($keys as $key) {
        expect(__("saved_posts.{$key}"))->not->toBe("saved_posts.{$key}");
    }
})->with(['en', 'ru', 'bg']);
