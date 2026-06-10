<?php

it('has import translation keys for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        app()->setLocale($locale);

        expect(__('import.from_url'))->not->toBe('import.from_url');
        expect(__('import.preview'))->not->toBe('import.preview');
        expect(__('import.errors.unsupported'))->not->toBe('import.errors.unsupported');
        expect(__('import.manual_upload_hint'))->not->toBe('import.manual_upload_hint');
    }
});

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
