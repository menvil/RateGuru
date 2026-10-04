<?php

use Illuminate\Support\Facades\Lang;

it('has the profile translation keys in every supported locale', function (string $locale) {
    foreach ([
        'profile.posts',
        'profile.saved',
        'profile.activity',
        'profile.edit_profile',
        'profile.visibility_private',
        'profile.visibility_public',
        'profile.activity_private',
        'profile.display_name',
        'profile.bio',
        'profile.website',
    ] as $key) {
        expect(Lang::hasForLocale($key, $locale))->toBeTrue("Missing {$key} for {$locale}");
    }
})->with(supportedLocales());
