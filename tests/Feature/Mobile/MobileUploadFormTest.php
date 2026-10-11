<?php

use App\Livewire\Feed\UploadPostForm;
use App\Models\User;
use Livewire\Livewire;

it('upload form renders with expected testid', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('data-testid="upload-form"', false);
});
