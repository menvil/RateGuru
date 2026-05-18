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

it('listens for post uploaded event to close upload modal', function () {
    $user = \App\Models\User::factory()->create();

    $html = $this->actingAs($user)->get('/')->getContent();

    expect($html)
        ->toContain('post-uploaded.window')
        ->toContain('open = false');
});

it('has alpine upload modal open close behavior', function () {
    $user = \App\Models\User::factory()->create();

    $html = $this->actingAs($user)->get('/')->getContent();

    expect($html)
        ->toContain('x-data')
        ->toContain('open: false')
        ->toContain('x-show')
        ->toContain('@click');
});

it('renders alpine drawer shell on feed page', function () {
    $html = $this->get('/')->getContent();

    expect($html)
        ->toContain('data-testid="post-detail-drawer-shell"')
        ->toContain('drawerOpen')
        ->toContain('x-show');
});

it('renders drawer close button', function () {
    $html = $this->get('/')->getContent();

    expect($html)
        ->toContain('data-testid="post-drawer-close"')
        ->toContain('drawerOpen = false');
});
