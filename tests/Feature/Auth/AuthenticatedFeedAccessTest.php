<?php

use App\Models\User;

it('allows authenticated users to access the feed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertOk();
});

it('redirects authenticated users from dashboard to feed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('feed'));
});
