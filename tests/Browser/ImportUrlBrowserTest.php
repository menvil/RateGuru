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

it('shows the drop zone and the link field together, with no tabs', function () {
    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->assertVisible('[data-testid="upload-image-dropzone"]')
        ->assertVisible('[data-testid="upload-image-url-input"]')
        ->assertMissing('[data-testid="image-tab-url"]');
});

it('lets the chosen file go when a link is typed, so no picture shows that would not be posted', function () {
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

    $page->type('[data-testid="upload-image-url-input"]', 'https://images.rateguru.test/dish.jpg');

    waitForScript($page, "document.querySelector('[data-testid=\"upload-image-dropzone\"] img') === null && document.querySelector('#image').files.length === 0");
});

it('lets a typed link go when a file is chosen', function () {
    $page = visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->type('[data-testid="upload-image-url-input"]', 'https://images.rateguru.test/dish.jpg');

    // A file chosen after the link, through the input's own change event: the
    // link lets go as the file is picked, before anything is uploaded.
    $page->script(<<<'JS'
        (() => {
            const files = new DataTransfer();
            files.items.add(new File(['dish'], 'dish.png', { type: 'image/png' }));
            const input = document.querySelector('#image');
            input.files = files.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })()
    JS);

    waitForScript($page, "document.querySelector('[data-testid=\"upload-image-url-input\"]').value === ''");
});

it('sends a source link a typed link hides, and shows it with what is wrong', function () {
    bindFakeHostResolver(['images.rateguru.test' => ['93.184.216.34']]);
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(640, 480)),
    ]));

    $page = visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->type('#title', 'A dish with a wrong source')
        ->type('#source_url', 'not a link')
        ->type('[data-testid="upload-image-url-input"]', 'https://images.rateguru.test/dish.jpg')
        ->click('[data-testid="upload-form"] button[type="submit"]');

    waitForScript($page, "document.querySelector('#source_url').checkVisibility()");
    $page->assertSeeIn('[data-testid="field-error-source-url"]', __('validation.url', ['attribute' => 'source url']));
});
