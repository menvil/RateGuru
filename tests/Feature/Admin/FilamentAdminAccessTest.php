<?php

use App\Models\User;

it('exposes filament admin panel at /admin path', function () {
    $response = $this->get('/admin');

    expect($response->getStatusCode())->not->toBe(404);
});

it('redirects guest from admin panel to login', function () {
    $this->get('/admin')->assertRedirect();
});

it('registers the admin panel with id and path "admin"', function () {
    $panel = filament()->getPanel('admin');

    expect($panel)->not->toBeNull()
        ->and($panel->getId())->toBe('admin')
        ->and($panel->getPath())->toBe('admin');
});
