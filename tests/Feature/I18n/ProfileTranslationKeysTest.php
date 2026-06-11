<?php

it('has profile translation keys for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        app()->setLocale($locale);

        expect(__('profile.posts'))->not->toBe('profile.posts');
        expect(__('profile.saved'))->not->toBe('profile.saved');
        expect(__('profile.activity'))->not->toBe('profile.activity');
        expect(__('profile.edit_profile'))->not->toBe('profile.edit_profile');
    }
});

it('has profile privacy visibility keys for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        app()->setLocale($locale);

        expect(__('profile.visibility_private'))->not->toBe('profile.visibility_private');
        expect(__('profile.visibility_public'))->not->toBe('profile.visibility_public');
        expect(__('profile.activity_private'))->not->toBe('profile.activity_private');
    }
});

it('has profile display field keys for all locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        app()->setLocale($locale);

        expect(__('profile.display_name'))->not->toBe('profile.display_name');
        expect(__('profile.bio'))->not->toBe('profile.bio');
        expect(__('profile.website'))->not->toBe('profile.website');
    }
});
