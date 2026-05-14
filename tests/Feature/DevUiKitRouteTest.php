<?php

it('renders the dev ui kit in local and testing environments', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('RateGuru UI Kit')
        ->assertSee('Buttons')
        ->assertSee('Cards')
        ->assertSee('Forms')
        ->assertSee('Overlays')
        ->assertSee('Feedback')
        ->assertSee('Reference');

    $this->app->detectEnvironment(fn () => 'local');

    $this->get('/dev/ui-kit')->assertOk();
});

it('does not expose the dev ui kit in production-like environments', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->get('/dev/ui-kit')->assertNotFound();
});
