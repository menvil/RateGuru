<?php

use Illuminate\Support\Facades\Lang;

it('has follow translation keys for all supported locales', function (string $locale) {
    foreach ([
        'follows.follow',
        'follows.following',
        'follows.unfollow',
        'follows.followers',
        'follows.following_count',
        'follows.login_required',
        'follows.feature_disabled',
        'follows.notifications.followed_author_posted',
        'follows.notifications.preference_label',
        'follows.notifications.preference_description',
    ] as $key) {
        expect(Lang::hasForLocale($key, $locale))->toBeTrue("Missing {$key} for {$locale}");
    }
})->with(supportedLocales());
