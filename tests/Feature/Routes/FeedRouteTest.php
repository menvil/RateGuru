<?php

it('serves feed page on home route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('RateGuru')
        ->assertDontSee('Discover dishes');
});

it('renders base feed layout with section title', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="app-header"', false)
        ->assertSee('Latest dishes')
        ->assertDontSee('data-testid="search-input"', false);
});

it('renders header search with responsive submit behavior', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="app-header-search"', false)
        ->assertSee('x-on:input.debounce.350ms', false)
        ->assertSee('x-on:search', false);
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
        ->assertSee('Upload')
        ->assertSee('Create post')
        ->assertSee('data-testid="open-upload-button"', false)
        ->assertSee('shadow-rgUpload', false)
        ->assertSee('aria-hidden="true"', false)
        ->assertSee('data-testid="upload-modal"', false);
});

it('renders authenticated header actions without changing guest header behavior', function () {
    $user = \App\Models\User::factory()->create();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('data-testid="notification-bell"', false);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('data-testid="app-header-search"', false)
        ->assertSee('data-testid="notification-bell"', false)
        ->assertSee('data-testid="header-profile-link"', false)
        ->assertSee('data-testid="header-user-menu-trigger"', false)
        ->assertSee('Profile')
        ->assertSee('Log out');
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

it('renders centered reference feed before a post is selected', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('data-testid="feed-content-shell"')
        ->toContain('max-w-[820px]')
        ->not->toContain('data-testid="post-detail-column"')
        ->not->toContain('data-drawer-id="post-detail-drawer"');
});

it('does not reserve detail column before a post is selected', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('Select a post')
        ->not->toContain('Post details will appear here.');
});

it('post cards select posts for the detail column', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('select-post')
        ->not->toContain('open-post-drawer');
});
