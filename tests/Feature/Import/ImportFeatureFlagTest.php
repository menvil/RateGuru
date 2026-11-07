<?php

use App\Actions\Import\ImportFromUrlAction;
use App\Actions\Import\StoreImportedImageAction;
use App\Exceptions\Import\UrlImportDisabledException;
use App\Models\ProjectSettings;
use App\Support\Import\ImportHttpTransport;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    bindFakeHostResolver();
});

it('blocks url import when feature flag is disabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'allow_url_imports' => false,
        ],
    ]);

    app(ProjectSettingsManager::class)->flush();

    app(ImportFromUrlAction::class)->handle('https://example.com/page');
})->throws(UrlImportDisabledException::class);

it('allows url import when feature flag is enabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'allow_url_imports' => true,
        ],
    ]);

    app(ProjectSettingsManager::class)->flush();

    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:title" content="Test"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(ImportFromUrlAction::class)->handle('https://example.com/page');

    expect($preview->isSupported())->toBeTrue();
});

it('allows url import when feature flag is absent using default true', function () {
    app(ProjectSettingsManager::class)->flush();

    Http::fake([
        'example.com/page' => Http::response(
            '<head><title>Test</title></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(ImportFromUrlAction::class)->handle('https://example.com/page');

    expect($preview)->not->toBeNull();
});

it('downloads no direct image either while url imports are switched off', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['allow_url_imports' => false]]);
    app(ProjectSettingsManager::class)->flush();
    $transport = new ScriptedImportHttpTransport([]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(StoreImportedImageAction::class)->download('https://example.com/image.jpg'))
        ->toThrow(UrlImportDisabledException::class)
        ->and($transport->calls)->toBe([]);
});
