<?php

it('has follow translation keys for all supported locales', function (string $locale) {
    app()->setLocale($locale);

    expect(__('follows.follow'))->not->toBe('follows.follow');
    expect(__('follows.following'))->not->toBe('follows.following');
    expect(__('follows.unfollow'))->not->toBe('follows.unfollow');
    expect(__('follows.followers'))->not->toBe('follows.followers');
    expect(__('follows.following_count'))->not->toBe('follows.following_count');
    expect(__('follows.login_required'))->not->toBe('follows.login_required');
    expect(__('follows.feature_disabled'))->not->toBe('follows.feature_disabled');
    expect(__('follows.notifications.followed_author_posted'))->not->toBe('follows.notifications.followed_author_posted');
    expect(__('follows.notifications.preference_label'))->not->toBe('follows.notifications.preference_label');
    expect(__('follows.notifications.preference_description'))->not->toBe('follows.notifications.preference_description');
})->with(['en', 'ru', 'bg']);
