<?php

use App\Models\ProjectSettings;
use App\Models\User;

it('renders configured upload label', function () {
    ProjectSettings::factory()->create([
        'feed_title' => 'Latest cats',
        'upload_cta_label' => 'Upload cat',
        'object_singular_name' => 'cat',
        'object_plural_name' => 'cats',
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Upload cat');
});

it('renders feed page without feed title heading', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertDontSee('data-testid="feed-title"', false);
});

it('renders fallback upload label when settings row is missing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Upload post');
});
