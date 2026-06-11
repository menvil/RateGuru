<?php

use Illuminate\Support\Facades\Lang;

it('has profile translation keys for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        expect(Lang::has('profile.posts', $locale))->toBeTrue("Missing profile.posts for {$locale}");
        expect(Lang::has('profile.saved', $locale))->toBeTrue("Missing profile.saved for {$locale}");
        expect(Lang::has('profile.activity', $locale))->toBeTrue("Missing profile.activity for {$locale}");
        expect(Lang::has('profile.edit_profile', $locale))->toBeTrue("Missing profile.edit_profile for {$locale}");
    }
});

it('has profile privacy visibility keys for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        expect(Lang::has('profile.visibility_private', $locale))->toBeTrue("Missing profile.visibility_private for {$locale}");
        expect(Lang::has('profile.visibility_public', $locale))->toBeTrue("Missing profile.visibility_public for {$locale}");
        expect(Lang::has('profile.activity_private', $locale))->toBeTrue("Missing profile.activity_private for {$locale}");
    }
});

it('has profile display field keys for all locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        expect(Lang::has('profile.display_name', $locale))->toBeTrue("Missing profile.display_name for {$locale}");
        expect(Lang::has('profile.bio', $locale))->toBeTrue("Missing profile.bio for {$locale}");
        expect(Lang::has('profile.website', $locale))->toBeTrue("Missing profile.website for {$locale}");
    }
});
