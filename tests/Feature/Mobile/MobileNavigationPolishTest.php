<?php

use App\Models\Post;
use App\Models\User;

it('places the theme switcher in the mobile navigation menu', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="mobile-nav-theme"', false)
        ->assertSee('data-testid="desktop-header-theme"', false);
});

it('renders a temporary mobile search row controlled by the search button', function () {
    $html = $this->get(route('feed'))->assertOk()->getContent();

    expect($html)
        ->toContain('aria-controls="mobile-search-row"')
        ->toContain('id="mobile-search-row"')
        ->toContain('mobileSearchOpen = ! mobileSearchOpen')
        ->toContain('x-show="mobileSearchOpen"');
});

it('keeps the search icon and logo through the landscape mobile breakpoint', function () {
    $html = $this->get(route('feed'))->assertOk()->getContent();

    expect($html)
        ->toMatch('/<form(?=[^>]*data-testid="app-header-search")(?=[^>]*class="[^"]*hidden[^"]*lg:block")[^>]*>/s')
        ->toMatch('/<button(?=[^>]*data-testid="mobile-search-trigger")(?=[^>]*class="[^"]*lg:hidden")[^>]*>/s')
        ->not->toMatch('/<form(?=[^>]*data-testid="app-header-search")(?=[^>]*class="[^"]*md:block")[^>]*>/s');
});

it('renders a mobile search submit button and clear controls for active search', function () {
    $this->get(route('feed', ['search' => 'mobile query']))
        ->assertOk()
        ->assertSee('data-testid="mobile-search-submit"', false)
        ->assertSee('data-testid="mobile-search-clear"', false)
        ->assertSee('data-testid="desktop-search-clear"', false)
        ->assertSee(__('ui.feed.search_button'))
        ->assertSee(__('ui.feed.clear_search'));
});

it('does not render search clear controls without an active query', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertDontSee('data-testid="mobile-search-clear"', false)
        ->assertDontSee('data-testid="desktop-search-clear"', false);
});

it('shows guests a create post action that explains authentication is required', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="guest-upload-button"', false)
        ->assertSee(__('ui.upload.sign_up_required'));
});

it('keeps the authenticated upload action unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="open-upload-button"', false)
        ->assertDontSee('data-testid="guest-upload-button"', false);
});

it('uses smaller mobile feed gutters and a full-width empty state', function () {
    $html = $this->get(route('feed'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-testid="app-main"')
        ->toContain('px-2 py-4 sm:px-6 sm:py-6')
        ->toContain('data-testid="feed-empty-state"')
        ->toContain('-mx-2 w-[calc(100%+1rem)]');
});

it('keeps post cards inside the smaller mobile feed gutters', function () {
    Post::factory()->published()->create();

    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="post-card"', false);
});
