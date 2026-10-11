<?php

use App\Models\Post;
use App\Models\User;

it('lays out the guest mobile header, navigation, search and feed gutters', function () {
    $html = $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="guest-upload-button"', false)
        ->assertDontSee('data-testid="mobile-search-clear"', false)
        ->assertDontSee('data-testid="desktop-search-clear"', false)
        ->getContent();
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    // The theme switcher sits in the mobile navigation menu as well as in the desktop header.
    expect($html)
        ->toContain('data-testid="mobile-nav-theme"')
        ->toContain('data-testid="desktop-header-theme"');

    expect($layout)
        ->toMatch('/<div class="mb-5" data-testid="mobile-nav-theme">\s*<livewire:theme\.theme-switcher layout="dropdown" \/>/s');

    expect($html)
        // An eighty five percent mobile drawer, without a fixed narrow width.
        ->toMatch('/<div(?=[^>]*data-testid="mobile-nav-panel")(?=[^>]*class="[^"]*w-\[85vw\][^"]*")[^>]*>/s')
        ->not->toMatch('/<div(?=[^>]*data-testid="mobile-nav-panel")(?=[^>]*class="[^"]*w-72[^"]*")[^>]*>/s')
        // A temporary mobile search row controlled by the search button.
        ->toContain('aria-controls="mobile-search-row"')
        ->toContain('id="mobile-search-row"')
        ->toContain('mobileSearchOpen = ! mobileSearchOpen')
        ->toContain('x-show="mobileSearchOpen"')
        // The search icon and the logo hold through the landscape mobile breakpoint.
        ->toMatch('/<form(?=[^>]*data-testid="app-header-search")(?=[^>]*class="[^"]*hidden[^"]*lg:block")[^>]*>/s')
        ->toMatch('/<button(?=[^>]*data-testid="mobile-search-trigger")(?=[^>]*class="[^"]*lg:hidden")[^>]*>/s')
        ->not->toMatch('/<form(?=[^>]*data-testid="app-header-search")(?=[^>]*class="[^"]*md:block")[^>]*>/s')
        // The guest create post action opens the sign-up dialog.
        ->toContain("rgOpenAuthModal(\$event, 'register'")
        ->not->toContain("\$dispatch('toast'")
        // Smaller mobile feed gutters and a full-width empty state.
        ->toContain('data-testid="app-main"')
        ->toContain('px-2 py-4 sm:px-6 sm:py-6')
        ->toContain('data-testid="feed-empty-state"')
        ->toContain('-mx-2 w-[calc(100%+1rem)]')
        ->toContain('rounded-none!')
        ->toContain('sm:rounded-rgCard!');
});

it('keeps the authenticated upload action and mobile search beside the header actions', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="open-upload-button"', false)
        ->assertDontSee('data-testid="guest-upload-button"', false)
        ->getContent();

    expect($html)
        ->toContain('data-testid="header-auth-actions"')
        ->not->toMatch('/<div(?=[^>]*data-testid="header-auth-actions")(?=[^>]*class="[^"]*ml-auto[^"]*")[^>]*>/s');
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

it('keeps post cards inside the smaller mobile feed gutters', function () {
    Post::factory()->published()->create();

    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="post-card"', false);
});
