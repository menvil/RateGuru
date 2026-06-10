<?php

use App\Livewire\Import\ImportUrlForm;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('shows clear message for unsupported social import', function () {
    app(ProjectSettingsManager::class)->flush();

    Http::fake([
        'www.instagram.com/*' => Http::response('', 403),
    ]);

    Livewire::test(ImportUrlForm::class)
        ->set('url', 'https://www.instagram.com/p/abc')
        ->call('import')
        ->assertSee('cannot be imported automatically');
});

it('shows unsafe url error message', function () {
    app(ProjectSettingsManager::class)->flush();

    Livewire::test(ImportUrlForm::class)
        ->set('url', 'http://192.168.1.1/image.jpg')
        ->call('import')
        ->assertSee('not safe');
});

it('shows validation error for invalid url format', function () {
    app(ProjectSettingsManager::class)->flush();

    Livewire::test(ImportUrlForm::class)
        ->set('url', 'not-a-url')
        ->call('import')
        ->assertHasErrors(['url']);
});

it('shows manual upload hint when url is unsupported', function () {
    app(ProjectSettingsManager::class)->flush();

    Http::fake([
        'www.instagram.com/*' => Http::response('', 403),
    ]);

    Livewire::test(ImportUrlForm::class)
        ->set('url', 'https://www.instagram.com/p/abc')
        ->call('import')
        ->assertSee('manually');
});

it('does not expose internal exception class names', function () {
    app(ProjectSettingsManager::class)->flush();

    Http::fake([
        'example.com/*' => Http::response('', 500),
    ]);

    $result = Livewire::test(ImportUrlForm::class)
        ->set('url', 'https://example.com/page')
        ->call('import');

    $html = $result->html();

    expect($html)->not->toContain('Exception');
    expect($html)->not->toContain('Stack trace');
});
