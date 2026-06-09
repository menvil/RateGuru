<?php

use App\Livewire\Theme\ThemeSwitcher;
use Livewire\Livewire;

it('renders language switcher with mobile testid', function () {
    $response = $this->get(route('feed'));

    $response->assertSee('data-testid="language-switcher"', false);
    $response->assertSee('data-testid="locale-switcher-trigger"', false);
});

it('locale switcher trigger meets 40px tap target height', function () {
    $html = $this->get(route('feed'))->content();

    $triggerPos = strpos($html, 'locale-switcher-trigger');
    expect($triggerPos)->not->toBeFalse('locale-switcher-trigger not found');

    // Check that h-10 (40px) appears within the trigger element's markup
    $snippet = substr($html, max(0, $triggerPos - 300), 500);
    expect($snippet)->toContain('h-10');
});

it('renders theme switcher with testid', function () {
    $html = Livewire::test(ThemeSwitcher::class)->html();

    expect($html)->toContain('data-testid="theme-switcher"');
});
