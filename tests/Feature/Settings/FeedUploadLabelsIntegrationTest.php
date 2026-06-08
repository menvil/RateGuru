<?php

use App\Models\ProjectSettings;
use App\Models\User;

it('renders configured feed title and upload label', function () {
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
        ->assertSee('Latest cats')
        ->assertSee('Upload cat');
});

it('renders fallback feed title when settings row is missing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Latest posts');
});

it('renders fallback upload label when settings row is missing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Upload post');
});
