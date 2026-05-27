<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('opens upload modal from feed', function () {
    actingAs(User::factory()->create());

    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->assertVisible('[data-testid="upload-modal"]')
        ->assertSee('Create post');
});
