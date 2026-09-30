<?php

use App\Models\User;

it('renders language switcher with supported locales', function () {
    $response = $this->get(route('feed'))->assertOk();

    foreach (config('locales.supported') as $info) {
        $response->assertSee($info['native']);
    }
});

it('renders language switcher for authenticated user', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('feed'))
        ->assertOk();

    foreach (config('locales.supported') as $info) {
        $response->assertSee($info['native']);
    }
});
