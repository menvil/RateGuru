<?php

it('serves feed page on home route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('RateGuru')
        ->assertSee('Discover dishes');
});

it('renders base feed layout with section title', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Latest dishes');
});

it('uses mobile-safe feed layout', function () {
    $response = $this->get('/');
    $response->assertOk();
    $html = $response->getContent();
    expect($html)
        ->toContain('px-4')
        ->toContain('max-w-');
});

it('renders upload modal shell for authenticated user on feed page', function () {
    $user = \App\Models\User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Create post')
        ->assertSee('data-testid="upload-modal"', false);
});
