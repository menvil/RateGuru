<?php

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Livewire\Feed\UploadPostForm;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('resets form when upload-modal-opened event is dispatched', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Old Dish')
        ->set('description', 'Old description')
        ->dispatch('upload-modal-opened')
        ->assertSet('title', '')
        ->assertSet('description', null)
        ->assertSet('submitError', null);
});

it('can render upload post form component', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertStatus(200);
});

it('renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Create post')
        ->assertStatus(200);
});

it('blocks guest users', function () {
    Livewire::test(UploadPostForm::class)
        ->assertForbidden();
});

it('has title input', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Title')
        ->assertSee('name="title"', false);
});

it('updates title property', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade pasta')
        ->assertSet('title', 'Homemade pasta');
});

it('has description textarea', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Description')
        ->assertSee('name="description"', false);
});

it('updates description property', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('description', 'Fresh pasta with basil')
        ->assertSet('description', 'Fresh pasta with basil');
});

it('has image file input', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Image')
        ->assertSee('type="file"', false)
        ->assertSee('name="image"', false);
});

it('accepts image upload property', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    $component = Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('image', $file);

    expect($component->get('image'))->not->toBeNull();
});

it('renders error message when submitError is set', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('submitError', 'Something went wrong while creating your post.')
        ->assertSee('Something went wrong');
});

it('has upload loading state markup', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->html();

    expect($html)
        ->toContain('wire:loading')
        ->toContain('Uploading');
});

it('dispatches successful upload event', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->set('image', $file)
        ->call('submit')
        ->assertDispatched('post-uploaded');
});

it('shows upload rate limit error without creating another post', function () {
    Storage::fake('public');
    config()->set('rate_limits.upload.max_attempts', 1);
    config()->set('rate_limits.upload.decay_seconds', 600);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'First Dish')
        ->set('image', UploadedFile::fake()->image('first.jpg'))
        ->call('submit')
        ->assertDispatched('post-uploaded');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Second Dish')
        ->set('image', UploadedFile::fake()->image('second.jpg'))
        ->call('submit')
        ->assertSet('submitError', 'You are uploading too quickly. Please try again later.')
        ->assertNotDispatched('post-uploaded');

    expect(Post::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not dispatch event on validation failure', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->call('submit')
        ->assertNotDispatched('post-uploaded');
});

it('shows validation error when title is missing', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('image', $file)
        ->call('submit')
        ->assertHasErrors(['title' => 'required']);

    expect(Post::query()->count())->toBe(0);
});

it('renders validation error placeholders', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->call('submit')
        ->assertSee('field-error-title', false)
        ->assertSee('field-error-image', false);
});

it('shows validation error when image is missing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->call('submit')
        ->assertHasErrors(['image' => 'required']);

    expect(Post::query()->count())->toBe(0);
});

it('creates post on successful upload', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->set('description', 'Fresh dinner')
        ->set('image', $file)
        ->call('submit');

    expect(Post::query()->where('user_id', $user->id)->where('title', 'Homemade Pasta')->exists())->toBeTrue();
});

it('renders selectable tags', function () {
    $user = User::factory()->create();
    $tag = \App\Models\Tag::factory()->create(['name' => 'Italian']);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Tags')
        ->assertSee('Italian')
        ->assertSee('data-testid="upload-tag-'.$tag->id.'"', false);
});

it('has cuisine truth selector', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Italian')
        ->assertSee('Asian')
        ->assertSee('American')
        ->assertSee('Mexican')
        ->assertSee('Other')
        ->assertSee('Keep unknown');
});

it('updates cuisineTruth property', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('cuisineTruth', CuisineType::Italian->value)
        ->assertSet('cuisineTruth', CuisineType::Italian->value);
});

it('has origin truth selector', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Homemade')
        ->assertSee('Restaurant')
        ->assertSee('Keep unknown');
});

it('updates originTruth property', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('originTruth', OriginType::Homemade->value)
        ->assertSet('originTruth', OriginType::Homemade->value);
});

it('has source url input', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Source URL')
        ->assertSee('name="source_url"', false);
});

it('updates sourceUrl property', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('sourceUrl', 'https://example.com/original')
        ->assertSet('sourceUrl', 'https://example.com/original');
});

it('has alpine image preview markup', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->html();

    expect($html)
        ->toContain('x-data')
        ->toContain('previewUrl')
        ->toContain('FileReader');
});
