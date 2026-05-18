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
