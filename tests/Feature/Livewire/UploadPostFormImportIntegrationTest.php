<?php

use App\Livewire\Feed\UploadPostForm;
use App\Models\User;
use Livewire\Livewire;

it('fills upload form from import preview', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->call('applyImportPreview', [
            'title' => 'Imported Title',
            'description' => 'Imported Description',
            'imageUrl' => 'https://example.com/image.jpg',
            'sourceUrl' => 'https://example.com/page',
        ])
        ->assertSet('title', 'Imported Title')
        ->assertSet('description', 'Imported Description')
        ->assertSet('sourceUrl', 'https://example.com/page')
        ->assertSet('importedImageUrl', 'https://example.com/image.jpg');
});

it('switches to upload mode when applying import preview', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->call('applyImportPreview', [
            'title' => 'Title',
            'description' => null,
            'imageUrl' => null,
            'sourceUrl' => 'https://example.com/page',
        ])
        ->assertSet('activeTab', 'upload');
});

it('upload form has import tab option', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('data-testid="import-tab"', false);
});

it('upload form has upload tab option', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('data-testid="upload-tab"', false);
});
