<?php

use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

use function Pest\Laravel\actingAs;

it('import from url tab is present in upload modal', function () {
    app(ProjectSettingsManager::class)->flush();

    actingAs(User::factory()->create());

    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->assertVisible('[data-testid="upload-modal"]')
        ->assertPresent('[data-testid="import-tab"]');
});

it('import url form shows when import tab is clicked', function () {
    app(ProjectSettingsManager::class)->flush();

    actingAs(User::factory()->create());

    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->click('[data-testid="import-tab"]')
        ->assertPresent('[data-testid="import-url-form"]');
});

it('import url input is present on import tab', function () {
    app(ProjectSettingsManager::class)->flush();

    actingAs(User::factory()->create());

    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->click('[data-testid="import-tab"]')
        ->assertPresent('[data-testid="import-url-input"]');
});
