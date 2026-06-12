<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Livewire\Feed\UploadPostForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
        ->assertSee('data-testid="image-tab-url"', false);
});

it('upload form has upload tab option', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('data-testid="image-tab-file"', false);
});

it('submit downloads importedImageUrl before validation and creates post', function () {
    $user = User::factory()->create();
    $fakeImage = UploadedFile::fake()->image('imported.jpg', 100, 100);

    $this->mock(StoreImportedImageAction::class)
        ->shouldReceive('download')
        ->with('https://example.com/image.jpg')
        ->once()
        ->andReturn($fakeImage);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Imported Dish')
        ->set('importedImageUrl', 'https://example.com/image.jpg')
        ->call('submit')
        ->assertDispatched('post-uploaded')
        ->assertHasNoErrors();
});
