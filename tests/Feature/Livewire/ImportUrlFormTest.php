<?php

use App\Livewire\Import\ImportUrlForm;
use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('renders import url form when feature is enabled', function () {
    app(ProjectSettingsManager::class)->flush();

    Livewire::test(ImportUrlForm::class)
        ->assertSee('data-testid="import-url-form"', false);
});

it('shows import preview after valid url submit', function () {
    app(ProjectSettingsManager::class)->flush();

    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:title" content="Preview Title"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    Livewire::test(ImportUrlForm::class)
        ->set('url', 'https://example.com/page')
        ->call('import')
        ->assertSet('previewTitle', 'Preview Title')
        ->assertSet('error', null);
});

it('shows error message when url is unsafe', function () {
    app(ProjectSettingsManager::class)->flush();

    Livewire::test(ImportUrlForm::class)
        ->set('url', 'http://localhost/test')
        ->call('import')
        ->assertSet('previewTitle', null)
        ->assertNotSet('error', null);
});

it('shows unsupported message when social provider blocks access', function () {
    app(ProjectSettingsManager::class)->flush();

    Http::fake([
        'www.instagram.com/*' => Http::response('', 403),
    ]);

    Livewire::test(ImportUrlForm::class)
        ->set('url', 'https://www.instagram.com/p/abc')
        ->call('import')
        ->assertSet('unsupported', true);
});

it('hides form when feature flag is disabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['allow_url_imports' => false],
    ]);

    app(ProjectSettingsManager::class)->flush();

    Livewire::test(ImportUrlForm::class)
        ->assertDontSee('data-testid="import-url-form"', false);
});

it('dispatches import-preview-selected event when user clicks use this', function () {
    app(ProjectSettingsManager::class)->flush();

    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:title" content="My Title"><meta property="og:image" content="https://example.com/img.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    Livewire::test(ImportUrlForm::class)
        ->set('url', 'https://example.com/page')
        ->call('import')
        ->call('usePreview')
        ->assertDispatched('import-preview-selected');
});
