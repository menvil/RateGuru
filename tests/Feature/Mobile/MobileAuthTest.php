<?php

it('login page renders with mobile-safe layout', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-testid="login-form"', false)
        ->assertSee('data-testid="login-submit"', false);
});

it('login action row uses flex-wrap to prevent overflow on mobile', function () {
    $response = $this->get(route('login'));

    $response->assertSee('flex-wrap', false);
});

it('register page renders with mobile testid', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('data-testid="register-form"', false);
});
