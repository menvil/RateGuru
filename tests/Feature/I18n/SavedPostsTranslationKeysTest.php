<?php

it('has saved posts translation keys for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        app()->setLocale($locale);

        expect(__('saved_posts.save'))->not->toBe('saved_posts.save');
        expect(__('saved_posts.saved'))->not->toBe('saved_posts.saved');
        expect(__('saved_posts.page_title'))->not->toBe('saved_posts.page_title');
        expect(__('saved_posts.empty_title'))->not->toBe('saved_posts.empty_title');
        expect(__('saved_posts.login_required'))->not->toBe('saved_posts.login_required');
        expect(__('saved_posts.feature_disabled'))->not->toBe('saved_posts.feature_disabled');
    }
});

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
