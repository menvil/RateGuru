<?php

use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('theme switcher is visible on the feed page', function () {
    visit(route('feed'))
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-switcher"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-system"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-light"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-dark"]');
});

it('can switch to light theme via theme switcher', function () {
    // The headless browser prefers light, so the page already starts light:
    // the test goes dark first, so that light is something the click did.
    $page = visit(route('feed'))
        ->click('[data-testid="desktop-header-theme"] [data-testid="theme-option-dark"]')
        ->assertAttributeContains('html[lang]', 'data-theme', 'dark');

    $page->click('[data-testid="desktop-header-theme"] [data-testid="theme-option-light"]')
        ->assertAttributeContains('html[lang]', 'data-theme', 'light')
        ->assertAttributeContains('html[lang]', 'data-theme-preference', 'light');
});

it('shows a guest the theme they chose, after a reload too, in every switcher on the page', function () {
    $page = visit(route('feed'));
    $pressed = fn (string $option): string => "[...document.querySelectorAll('[data-testid=\"theme-option-{$option}\"]')].map((button) => button.getAttribute('aria-pressed')).join(',')";

    $page->script("[...document.querySelectorAll('[data-testid=\"theme-option-dark\"]')].find((button) => button.offsetParent !== null).click()");
    waitForScript($page, 'document.documentElement.dataset.themePreference', 'dark');

    $page->navigate(route('feed'));

    waitForScript($page, $pressed('dark'), 'true,true');

    expect($page->script($pressed('system')))->toBe('false,false');
});

it('marks a guest\'s theme in every switcher after a page reached through a navigate link too', function () {
    $post = Post::factory()->published()->create();
    $page = visit(route('feed'));
    $pressed = fn (string $option): string => "[...document.querySelectorAll('[data-testid=\"theme-option-{$option}\"]')].map((button) => button.getAttribute('aria-pressed')).join(',')";

    $page->script("[...document.querySelectorAll('[data-testid=\"theme-option-dark\"]')].find((button) => button.offsetParent !== null).click()");
    waitForScript($page, 'document.documentElement.dataset.themePreference', 'dark');

    // The author's profile, reached as a reader reaches it: no full page load.
    $page->script("document.querySelector('a[wire\\\\:navigate][href=\"".route('profile.show', $post->user->public_username)."\"]').click()");
    waitForScript($page, 'location.pathname', parse_url(route('profile.show', $post->user->public_username), PHP_URL_PATH));

    waitForScript($page, $pressed('dark'), 'true,true');
});

it('lets the theme saved to the account outrank the one the browser kept, on a load and after a navigate link', function () {
    $user = User::factory()->create(['theme_preference' => 'dark']);
    $post = Post::factory()->published()->create();
    $profile = route('profile.show', $post->user->public_username);
    actingAs($user);
    $page = visit(route('feed'));

    // The browser kept another choice, from a guest or another account.
    $page->script("localStorage.setItem('rateguru.theme.preference', 'light')");
    $page->navigate(route('feed'));

    waitForScript($page, 'document.documentElement.dataset.theme', 'dark');
    waitForScript($page, "localStorage.getItem('rateguru.theme.preference')", 'dark');

    $page->script("localStorage.setItem('rateguru.theme.preference', 'light')");
    $page->script("document.querySelector('a[wire\\\\:navigate][href=\"{$profile}\"]').click()");
    waitForScript($page, 'location.pathname', parse_url($profile, PHP_URL_PATH));

    waitForScript($page, 'document.documentElement.dataset.themePreference', 'dark');
    waitForScript($page, 'document.documentElement.dataset.theme', 'dark');
});
