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
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('data-testid="post-detail-drawer-shell"')
        ->toContain('drawerOpen')
        ->toContain('x-show');
});

it('renders drawer close button', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('data-testid="post-drawer-close"')
        ->toContain('drawerOpen = false');
});

it('closes drawer with escape key markup', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('@keydown.escape.window')
        ->toContain('drawerOpen = false');
});

it('has mobile drawer behavior classes', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('data-testid="post-detail-drawer-shell"')
        ->toContain('bottom-0');
});

it('has desktop right side drawer behavior classes', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('md:right-0')
        ->toContain('md:inset-y-0');
});
