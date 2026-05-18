<?php

use App\Livewire\Feed\UploadPostForm;
use App\Models\User;
use Livewire\Livewire;

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
    $file = \Illuminate\Http\UploadedFile::fake()->image('dish.jpg');

    $component = Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('image', $file);

    expect($component->get('image'))->not->toBeNull();
});

it('shows validation error when image is missing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->call('submit')
        ->assertHasErrors(['image' => 'required']);

    expect(\App\Models\Post::query()->count())->toBe(0);
});

it('creates post on successful upload', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = \Illuminate\Http\UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->set('description', 'Fresh dinner')
        ->set('image', $file)
        ->call('submit');

    expect(\App\Models\Post::query()->where('user_id', $user->id)->where('title', 'Homemade Pasta')->exists())->toBeTrue();
});

it('renders tag input placeholder', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Tags')
        ->assertSee('Tag selection coming soon');
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
        ->set('cuisineTruth', \App\Enums\CuisineType::Italian->value)
        ->assertSet('cuisineTruth', \App\Enums\CuisineType::Italian->value);
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
        ->set('originTruth', \App\Enums\OriginType::Homemade->value)
        ->assertSet('originTruth', \App\Enums\OriginType::Homemade->value);
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
