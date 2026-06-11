<?php

use App\Actions\Import\ImportFromUrlAction;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\UrlImportValidator;
use Illuminate\Support\Facades\Log;

it('logs unsafe url as security event', function () {
    Log::spy();

    try {
        app(UrlImportValidator::class)->validate('http://127.0.0.1/admin');
    } catch (UnsafeImportUrlException) {
        // expected
    }

    Log::shouldHaveReceived('warning')
        ->with('url_import.unsafe_url_blocked', Mockery::any());
});

it('logs url import preview started', function () {
    Log::spy();

    $settings = \App\Models\ProjectSettings::factory()->create([
        'feature_flags' => ['allow_url_imports' => true],
    ]);

    try {
        app(ImportFromUrlAction::class)->handle('https://example.com/page');
    } catch (\Throwable) {
        // network error expected in tests
    }

    Log::shouldHaveReceived('info')
        ->with('url_import.preview.started', Mockery::any());
});

it('does not log full url with credentials in import context', function () {
    Log::spy();

    try {
        app(UrlImportValidator::class)->validate('http://localhost/secret?token=abc');
    } catch (UnsafeImportUrlException) {
        // expected
    }

    Log::shouldHaveReceived('warning')
        ->with('url_import.unsafe_url_blocked', Mockery::on(function ($context) {
            $encoded = json_encode($context);

            return ! str_contains($encoded, 'token=abc');
        }));
});
