<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use Livewire\Livewire;

it('does not render overlay chrome by default', function () {
    Livewire::test(PostDrawer::class)
        ->assertDontSee('data-testid="post-detail-overlay"', false)
        ->assertDontSee('data-testid="post-detail-overlay-host"', false);
});

it('mounts the overlay closed (inert, off-screen) when asOverlay is enabled with no post selected', function () {
    // The <aside> is always mounted when asOverlay is true (not gated by @if($isOpen)) so
    // Livewire's morph only ever updates its class attribute on an already-painted node,
    // letting the CSS transition fire naturally instead of needing JS timing tricks to
    // animate an element being inserted/removed from the DOM. Closed state is expressed as
    // 'inert' plus the off-screen translate class, not by the element's absence.
    $html = Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->assertSee('data-testid="post-detail-overlay-host"', false)
        ->assertSee('data-testid="post-detail-overlay"', false)
        ->html();

    expect($html)->toMatch('/inert\s+data-testid="post-detail-overlay"/');

    preg_match('/data-testid="post-detail-overlay"[^>]*class="([^"]*)"/', $html, $classMatch);
    expect($classMatch[1] ?? '')->toContain('translate-x-full');
});

it('opens and loads the post when a select-post event is received', function () {
    $post = Post::factory()->published()->create(['title' => 'Overlay Target Post']);

    $html = Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->dispatch('select-post', postId: $post->id)
        ->assertSet('postId', $post->id)
        ->assertSet('isOpen', true)
        ->assertSee('Overlay Target Post')
        ->html();

    expect($html)->not->toMatch('/inert\s+data-testid="post-detail-overlay"/');

    preg_match('/data-testid="post-detail-overlay"[^>]*class="([^"]*)"/', $html, $classMatch);
    expect($classMatch[1] ?? '')->not->toContain('translate-x-full');
});

it('swaps to a different post while staying open when select-post fires again', function () {
    $first = Post::factory()->published()->create(['title' => 'First Overlay Post']);
    $second = Post::factory()->published()->create(['title' => 'Second Overlay Post']);

    Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->dispatch('select-post', postId: $first->id)
        ->assertSet('isOpen', true)
        ->assertSee('First Overlay Post')
        ->dispatch('select-post', postId: $second->id)
        ->assertSet('isOpen', true)
        ->assertSet('postId', $second->id)
        ->assertSee('Second Overlay Post')
        ->assertDontSee('First Overlay Post');
});

it('closes when a clear-selected-post event is received', function () {
    $post = Post::factory()->published()->create();

    $html = Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->dispatch('select-post', postId: $post->id)
        ->assertSet('isOpen', true)
        ->dispatch('clear-selected-post')
        ->assertSet('isOpen', false)
        ->html();

    expect($html)->toMatch('/inert\s+data-testid="post-detail-overlay"/');

    preg_match('/data-testid="post-detail-overlay"[^>]*class="([^"]*)"/', $html, $classMatch);
    expect($classMatch[1] ?? '')->toContain('translate-x-full');
});

it('keeps the panel chrome static across renders so only the class attribute animates', function () {
    // Regression test: the slide must be a plain class swap on an always-mounted,
    // already-painted node — earlier versions gated the <aside> behind @if($isOpen) (so
    // Livewire's morph inserted/removed it fresh each time, giving the browser nothing to
    // transition from) and separately tried Alpine's x-transition directives (which rely
    // on morph-integration that didn't reliably fire either). Both looked correct in code
    // but produced no visible animation. The static (non-open-state) part of the class
    // list must be identical whether the panel just opened or is re-rendering while
    // already open (e.g. switching posts).
    $first = Post::factory()->published()->create(['title' => 'First Post']);
    $second = Post::factory()->published()->create(['title' => 'Second Post']);

    $test = Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->dispatch('select-post', postId: $first->id);

    preg_match('/data-testid="post-detail-overlay"[^>]*class="([^"]*)"/', $test->html(), $openMatch);
    expect($openMatch[1] ?? '')->not->toContain('translate-x-full');

    $test->dispatch('select-post', postId: $second->id);

    preg_match('/data-testid="post-detail-overlay"[^>]*class="([^"]*)"/', $test->html(), $switchMatch);
    expect($switchMatch[1] ?? '')->not->toContain('translate-x-full');
    expect($openMatch[1] ?? '')->toBe($switchMatch[1] ?? '');
});
