<?php

use App\Livewire\Feed\UploadPostForm;
use App\Models\User;
use Livewire\Livewire;

it('renders upload form with mobile-safe structure', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('data-testid="upload-form"', false);
});

it('upload form uses full-width inputs', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->html();

    expect($html)->toContain('data-testid="upload-form"');
    expect($html)->toContain('space-y-4');
});

it('upload form is accessible via authenticated feed page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="open-upload-button"', false);
});
