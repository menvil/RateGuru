<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Livewire\Feed\UploadPostForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
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

    $mock = $this->mock(StoreImportedImageAction::class);
    $mock->shouldReceive('download')
        ->with('https://example.com/image.jpg')
        ->once()
        ->andReturn($fakeImage);
    $mock->shouldReceive('cleanup')
        ->once()
        ->with($fakeImage);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Imported Dish')
        ->set('importedImageUrl', 'https://example.com/image.jpg')
        ->call('submit')
        ->assertDispatched('post-uploaded')
        ->assertDispatched('toast', message: __('ui.upload.success_pending'))
        ->assertHasNoErrors();
});

it('cleans up the imported temp file even when post creation fails validation', function () {
    // Called as a direct method on a resolved instance, not through
    // Livewire::test()->call(): the component's own validate() call, on a
    // raw UploadedFile assigned outside Livewire's normal ->set() upload
    // handling (exactly what download() returns), can't be snapshotted by
    // Livewire's own request/response cycle once it fails -- an unrelated,
    // pre-existing WithFileUploads limitation, not something this test is
    // about. Calling submit() directly sidesteps that snapshot step
    // entirely while still exercising the exact same finally-block cleanup
    // logic a real failed submission would run.
    $user = User::factory()->create();
    $this->actingAs($user);

    $fakeImage = UploadedFile::fake()->image('imported.jpg', 100, 100);

    $mock = $this->mock(StoreImportedImageAction::class);
    $mock->shouldReceive('download')
        ->once()
        ->andReturn($fakeImage);
    $mock->shouldReceive('cleanup')
        ->once()
        ->with($fakeImage);

    $form = app(UploadPostForm::class);
    $form->mount();
    $form->title = ''; // fails the "required|min:3" rule
    $form->importedImageUrl = 'https://example.com/image.jpg';

    try {
        $form->submit();
    } catch (ValidationException) {
        // Expected -- title is blank. The mock's ->once() expectation on
        // cleanup() is what this test is actually verifying.
    }
});
