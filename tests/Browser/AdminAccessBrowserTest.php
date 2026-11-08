<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('allows moderator to access admin panel', function () {
    actingAs(User::factory()->moderator()->create());

    visit('/admin')
        ->assertPathIs('/admin')
        ->assertSee('Dashboard')
        ->assertDontSee('Moderation Dashboard')
        ->assertNotPresent('[data-testid="admin-dashboard"]');
});

it('shows Remember me on the sign-in page only as checked or not, the same with the focus as without it', function () {
    $box = "document.querySelector('[id=\"form.remember\"]')";
    $shadow = "getComputedStyle({$box}).boxShadow";

    $page = visit('/admin/login');

    // Filament draws the box's own outline as a ring, so the focus may keep
    // that ring but must add nothing to it.
    $empty = $page->script($shadow);
    $page->script("{$box}.focus()");
    waitForScript($page, 'document.activeElement.id', 'form.remember');
    expect($page->script($shadow))->toBe($empty);

    $page->click('[id="form.remember"]');
    waitForScript($page, "{$box}.checked");
    $checkedFocused = $page->script($shadow);
    $page->script("{$box}.blur()");

    expect($page->script($shadow))->toBe($checkedFocused)
        ->and($page->script("getComputedStyle({$box}).backgroundImage"))->toContain('data:image/svg+xml')
        ->and($page->script("getComputedStyle({$box}).backgroundColor"))->not->toBe('rgb(255, 255, 255)');
});
