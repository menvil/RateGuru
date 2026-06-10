<?php

it('has sharing translation keys for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        app()->setLocale($locale);

        expect(__('sharing.share'))->not->toBe('sharing.share');
        expect(__('sharing.copy_link'))->not->toBe('sharing.copy_link');
        expect(__('sharing.facebook'))->not->toBe('sharing.facebook');
        expect(__('sharing.x'))->not->toBe('sharing.x');
        expect(__('sharing.telegram'))->not->toBe('sharing.telegram');
        expect(__('sharing.whatsapp'))->not->toBe('sharing.whatsapp');
        expect(__('sharing.reddit'))->not->toBe('sharing.reddit');
        expect(__('sharing.pinterest'))->not->toBe('sharing.pinterest');
        expect(__('sharing.email'))->not->toBe('sharing.email');
        expect(__('sharing.copied'))->not->toBe('sharing.copied');
        expect(__('sharing.native'))->not->toBe('sharing.native');
        expect(__('sharing.share_this_post'))->not->toBe('sharing.share_this_post');
        expect(__('sharing.share_unavailable'))->not->toBe('sharing.share_unavailable');
    }
});

it('has correct english sharing labels', function () {
    app()->setLocale('en');

    expect(__('sharing.share'))->toBe('Share');
    expect(__('sharing.copy_link'))->toBe('Copy link');
    expect(__('sharing.copied'))->toBe('Copied');
    expect(__('sharing.share_this_post'))->toBe('Share this post');
});

it('has russian sharing labels', function () {
    app()->setLocale('ru');

    expect(__('sharing.share'))->toBe('Поделиться');
    expect(__('sharing.copy_link'))->toBe('Скопировать ссылку');
    expect(__('sharing.copied'))->toBe('Скопировано');
    expect(__('sharing.share_this_post'))->toBe('Поделиться постом');
});

it('has bulgarian sharing labels', function () {
    app()->setLocale('bg');

    expect(__('sharing.share'))->toBe('Сподели');
    expect(__('sharing.copy_link'))->toBe('Копирай линк');
    expect(__('sharing.copied'))->toBe('Копирано');
    expect(__('sharing.share_this_post'))->toBe('Сподели поста');
});
