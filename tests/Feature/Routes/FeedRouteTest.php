<?php

use App\Enums\MediaVariantName;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('renders the guest feed shell with header search and a centred feed before a post is selected', function () {
    $response = $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="app-header"', false)
        ->assertDontSee('data-testid="search-input"', false)
        ->assertSee('data-testid="app-header-search"', false)
        ->assertDontSee('x-on:input.debounce.350ms', false)
        ->assertSee('x-on:search', false);

    expect($response->getContent())
        // Mobile-safe gutters and width.
        ->toContain('px-4')
        ->toContain('max-w-')
        // A centred feed, with no detail column reserved before a post is selected.
        ->toContain('data-testid="feed-content-shell"')
        ->toContain('max-w-[820px]')
        ->not->toContain('data-testid="post-detail-column"')
        ->not->toContain('data-drawer-id="post-detail-drawer"')
        ->not->toContain('Select a post')
        ->not->toContain('Post details will appear here.')
        // Post cards ask for the detail column rather than opening a drawer.
        ->toContain('select-post')
        ->not->toContain('open-post-drawer');
});

it('renders the upload modal shell for an authenticated user on the feed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Upload')
        ->assertSee('Upload post')
        ->assertSee('data-testid="open-upload-button"', false)
        ->assertSee('shadow-rgUpload', false)
        ->assertSee('aria-hidden="true"', false)
        ->assertSee('data-testid="upload-modal"', false)
        ->assertSee('overflow-visible', false);

    expect($response->getContent())
        // Alpine opens and closes the modal, and closes it once a post is uploaded.
        ->toContain('x-data')
        ->toContain('open: false')
        ->toContain('x-show')
        ->toContain('@click')
        ->toContain('post-uploaded.window')
        ->toContain('open = false');
});

it('renders authenticated header actions without changing guest header behavior', function () {
    $user = User::factory()->create();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('data-testid="notification-bell"', false)
        ->assertSee('data-testid="header-login-link"', false)
        ->assertSee('Log in');

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('data-testid="app-header-search"', false)
        ->assertSee('data-testid="notification-bell"', false)
        ->assertSee('data-testid="header-profile-link"', false)
        ->assertSee('data-testid="header-user-menu-trigger"', false)
        ->assertDontSee('data-testid="header-login-link"', false)
        ->assertSee('Profile')
        ->assertSee('Log out');
});

it('resolves header user menu avatar via the resolved avatar accessor', function () {
    Storage::fake('public');

    $user = User::factory()->withAvatar(path: 'avatars/header-user.jpg')->create();

    $html = $this->actingAs($user)->get('/')->getContent();

    expect($html)->toContain('avatars/header-user.jpg');
});

it('preloads the header avatar variants relation instead of leaving resolved_avatar_srcset stuck on the master fallback', function () {
    Storage::fake('public');

    $user = User::factory()->withAvatar(path: 'avatars/header-user.jpg')->create();
    MediaVariant::factory()->named(MediaVariantName::Avatar128)->create([
        'media_asset_id' => $user->avatar_asset_id,
        'disk' => 'public',
        'path' => 'avatars/header-user-128.jpg',
        'width' => 128,
        'height' => 128,
    ]);

    $variantQueries = 0;
    DB::listen(function ($query) use (&$variantQueries): void {
        if (str_contains($query->sql, 'media_variants')) {
            $variantQueries++;
        }
    });

    $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

    // AvatarUrlResolver::responsive() never lazy-loads variants — a caller
    // that hasn't eager-loaded avatarAsset.variants gets the master-only
    // fallback (no srcset) instead of an N+1 query. Asserting on the
    // rendered srcset itself — not just the query count — is what actually
    // catches layouts.app's loadMissing('avatarAsset.variants') being
    // removed: without it, this page would still render fine and still cost
    // some fixed, plausible-looking query count, just with the header
    // avatar silently missing its srcset (asserted by AvatarUrlResolver's
    // own unit tests, not visible here without checking the markup itself).
    expect($html)->toContain('avatars/header-user-128.jpg');

    // The only query against media_variants on this page comes from the
    // composer's single loadMissing() call, not one per accessor call
    // (resolved_avatar_url / resolved_avatar_srcset are both read from the
    // header markup).
    expect($variantQueries)->toBe(1);
});
