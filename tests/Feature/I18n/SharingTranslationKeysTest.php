<?php

use Illuminate\Support\Facades\Lang;

it('has sharing translation keys in every supported locale', function (string $locale) {
    foreach ([
        'share', 'copy_link', 'facebook', 'x', 'telegram', 'whatsapp', 'reddit',
        'pinterest', 'email', 'copied', 'native', 'share_this_post', 'share_unavailable',
    ] as $key) {
        expect(Lang::hasForLocale("sharing.{$key}", $locale))->toBeTrue("Missing sharing.{$key} for {$locale}");
    }
})->with(representativeLocales());

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
