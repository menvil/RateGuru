<?php

use Illuminate\Support\Facades\Lang;

it('has all required saved posts translation keys in each locale', function (string $locale) {
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
        expect(Lang::hasForLocale("saved_posts.{$key}", $locale))->toBeTrue("Missing saved_posts.{$key} for {$locale}");
    }
})->with(supportedLocales());
