<?php

use App\Support\Auth\AuthSurfaceContext;

/*
 * The modal markers a social sign-in link carries are optional and advisory.
 * An unusable one — a query string someone copied wrong, or an array where a
 * string was expected — must fall back to the standalone-page flow, which is
 * exactly what AuthSurfaceContext, AuthModalMode and AuthReturnUrl all already
 * do with a value they do not recognise. Validating them as strings turned that
 * documented fallback into a refused sign-in.
 */

beforeEach(function () {
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'https://rateguru.test/auth/google/callback');
});

it('starts the OAuth flow despite an unusable optional marker', function (array $query) {
    $response = $this->get('/auth/google?'.http_build_query($query));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://accounts.google.com/');

    // Fell back to the page flow rather than refusing, and remembered no modal
    // context to come back to.
    expect(AuthSurfaceContext::pull(session()->driver())->isModal())->toBeFalse();
})->with([
    'surface as an array' => [[AuthSurfaceContext::SURFACE_FIELD => ['modal']]],
    'nested array' => [[AuthSurfaceContext::SURFACE_FIELD => ['deeper' => ['modal']]]],
]);

it('starts the OAuth flow in modal mode despite an unusable mode or return marker', function (array $query) {
    $response = $this->get('/auth/google?'.http_build_query($query));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://accounts.google.com/');

    // The surface itself was usable, so the modal is remembered — with the
    // unusable part answered by its own documented fallback.
    expect(AuthSurfaceContext::pull(session()->driver())->isModal())->toBeTrue();
})->with([
    'mode as an array' => [[
        AuthSurfaceContext::SURFACE_FIELD => 'modal',
        AuthSurfaceContext::MODE_FIELD => ['register'],
    ]],
    'return path as an array' => [[
        AuthSurfaceContext::SURFACE_FIELD => 'modal',
        AuthSurfaceContext::RETURN_FIELD => ['/feed'],
    ]],
]);

it('still honours a well-formed modal marker', function () {
    $this->get('/auth/google?'.http_build_query([
        AuthSurfaceContext::SURFACE_FIELD => 'modal',
        AuthSurfaceContext::MODE_FIELD => 'register',
    ]))->assertRedirect();

    expect(AuthSurfaceContext::pull(session()->driver())->isModal())->toBeTrue();
});
