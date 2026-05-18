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
