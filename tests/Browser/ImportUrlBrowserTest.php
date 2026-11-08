<?php

use App\Models\User;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use App\Support\Settings\ProjectSettingsManager;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(ProjectSettingsManager::class)->flush();
    actingAs(User::factory()->create());
});

it('import url input is present on import tab', function () {
    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->click('[data-testid="image-tab-url"]')
        ->assertVisible('[data-testid="upload-image-url-input"]');
});

it('lets the chosen file go when the link tab is taken, so the file tab shows no picture it would not post', function () {
    $page = visit(route('feed'))->click('[data-testid="open-upload-button"]');

    // A picture chosen from the device, as the form keeps it in the browser.
    // Only the browser's side: the test server takes no uploaded files.
    $page->script(<<<'JS'
        (() => {
            const files = new DataTransfer();
            files.items.add(new File(['dish'], 'dish.png', { type: 'image/png' }));
            document.querySelector('#image').files = files.files;
            const form = Alpine.$data(document.querySelector('[data-testid="upload-form"]'));
            form.fileName = 'dish.png';
            form.previewUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
        })()
    JS);
    waitForScript($page, "!! document.querySelector('[data-testid=\"upload-image-dropzone\"] img')");

    $page->click('[data-testid="image-tab-url"]')->click('[data-testid="image-tab-file"]');

    waitForScript($page, "document.querySelector('[data-testid=\"upload-image-dropzone\"] img') === null && document.querySelector('#image').files.length === 0");
});

it('lets a typed link go when the file tab is taken', function () {
    $page = visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->click('[data-testid="image-tab-url"]')
        ->type('[data-testid="upload-image-url-input"]', 'https://images.rateguru.test/dish.jpg')
        ->click('[data-testid="image-tab-file"]')
        ->click('[data-testid="image-tab-url"]');

    $page->assertValue('[data-testid="upload-image-url-input"]', '');
});

it('sends a source link the link tab hides, and shows it with what is wrong', function () {
    bindFakeHostResolver(['images.rateguru.test' => ['93.184.216.34']]);
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(640, 480)),
    ]));

    $page = visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->type('#title', 'A dish with a wrong source')
        ->type('#source_url', 'not a link')
        ->click('[data-testid="image-tab-url"]')
        ->type('[data-testid="upload-image-url-input"]', 'https://images.rateguru.test/dish.jpg')
        ->click('[data-testid="upload-form"] button[type="submit"]');

    waitForScript($page, "document.querySelector('#source_url').checkVisibility()");
    $page->assertSeeIn('[data-testid="field-error-source-url"]', __('validation.url', ['attribute' => 'source url']));
});
