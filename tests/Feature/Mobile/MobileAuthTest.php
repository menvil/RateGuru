<?php

it('login page renders with a mobile-safe layout and a wrapping action row', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-testid="login-form"', false)
        ->assertSee('data-testid="login-submit"', false)
        ->assertSee('flex-wrap', false);
});

it('register page renders with mobile testid', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('data-testid="register-form"', false);
});
