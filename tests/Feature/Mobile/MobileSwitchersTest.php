<?php

use App\Livewire\Theme\ThemeSwitcher;
use Livewire\Livewire;

it('renders language switcher with mobile testid', function () {
    $response = $this->get(route('feed'));

    $response->assertSee('data-testid="language-switcher"', false);
    $response->assertSee('data-testid="locale-switcher-trigger"', false);
});

it('locale switcher trigger meets 40px tap target height', function () {
    $response = $this->get(route('feed'));

    $response->assertSee('h-10', false);
});

it('renders theme switcher with testid', function () {
    $html = Livewire::test(ThemeSwitcher::class)->html();

    expect($html)->toContain('data-testid="theme-switcher"');
});
