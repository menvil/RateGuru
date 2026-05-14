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

it('renders button variants in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Primary Button')
        ->assertSee('Secondary Button')
        ->assertSee('Ghost Button')
        ->assertSee('Danger Button')
        ->assertSee('Disabled Button');
});

it('renders card variants in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Default Card')
        ->assertSee('Elevated Card')
        ->assertSee('Interactive Card')
        ->assertSee('Food Image Placeholder');
});

it('renders modal shell in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Open Modal')
        ->assertSee('Upload Dish Preview');
});

it('renders drawer shell in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Open Drawer')
        ->assertSee('Dish Details Preview')
        ->assertSee('Homemade or Restaurant?');
});

it('renders form controls in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Dish title')
        ->assertSee('Description')
        ->assertSee('Validation error example')
        ->assertSee('Disabled input');
});
