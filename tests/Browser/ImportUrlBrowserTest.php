<?php

use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(ProjectSettingsManager::class)->flush();
    actingAs(User::factory()->create());
});

it('import from url tab is present in upload modal', function () {
    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->assertVisible('[data-testid="upload-modal"]')
        ->assertVisible('[data-testid="import-tab"]');
});

it('import url form shows when import tab is clicked', function () {
    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->click('[data-testid="import-tab"]')
        ->assertVisible('[data-testid="import-url-form"]');
});

it('import url input is present on import tab', function () {
    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->click('[data-testid="import-tab"]')
        ->assertVisible('[data-testid="import-url-input"]');
});
