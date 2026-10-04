<?php

use Illuminate\Support\Facades\Lang;

it('has import translation keys in every supported locale', function (string $locale) {
    foreach (['import.from_url', 'import.preview', 'import.errors.unsupported', 'import.manual_upload_hint'] as $key) {
        expect(Lang::hasForLocale($key, $locale))->toBeTrue("Missing {$key} for {$locale}");
    }
})->with(supportedLocales());

it('has all required import keys in english', function () {
    app()->setLocale('en');

    $required = [
        'import.from_url',
        'import.paste_url',
        'import.import',
        'import.preview',
        'import.use_this',
        'import.cancel',
        'import.loading',
        'import.errors.invalid_url',
        'import.errors.unsafe_url',
        'import.errors.timeout',
        'import.errors.too_large',
        'import.errors.unsupported',
        'import.errors.provider_blocked',
        'import.errors.feature_disabled',
        'import.errors.fetch_failed',
        'import.unsupported_reason_download_and_upload',
        'import.manual_upload_hint',
    ];

    foreach ($required as $key) {
        expect(__($key))->not->toBe($key, "Missing translation key: {$key}");
    }
});
