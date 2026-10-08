<?php

use App\Models\User;
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
