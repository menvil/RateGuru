<?php

it('renders the language switcher with a 40px tap target on its trigger', function () {
    $response = $this->get(route('feed'));

    $response->assertSee('data-testid="language-switcher"', false);
    $response->assertSee('data-testid="locale-switcher-trigger"', false);

    $html = $response->content();
    $triggerPos = strpos($html, 'locale-switcher-trigger');
    expect($triggerPos)->not->toBeFalse('locale-switcher-trigger not found');

    // Check that h-10 (40px) appears within the trigger element's markup
    $snippet = substr($html, max(0, $triggerPos - 300), 500);
    expect($snippet)->toContain('h-10');
});
