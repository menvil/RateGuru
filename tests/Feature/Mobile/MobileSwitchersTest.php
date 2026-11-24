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

it('draws the side panel’s language switcher the height of its close button, still 40px to the finger', function () {
    $trigger = livewireDom($this->get(route('feed'))->assertOk()->getContent())
        ->query("//*[@data-testid='mobile-nav-close']/parent::*//*[@data-testid='locale-switcher-trigger']")
        ->item(0);

    expect($trigger)->not->toBeNull()
        ->and($trigger->getAttribute('class'))->toContain('h-8')
        ->toContain('before:-inset-1')
        ->not->toContain('h-10');
});
