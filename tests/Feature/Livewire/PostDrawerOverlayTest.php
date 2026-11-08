<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use App\Models\User;
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

it('drops the deleted post\'s card from the list behind the drawer', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    Livewire::actingAs($user)
        ->test(PostDrawer::class, ['asOverlay' => true])
        ->call('setSelectedPost', $post->id)
        ->call('deleteSelectedPost')
        ->assertDispatched('post-deleted', postId: $post->id)
        ->assertDispatched('clear-selected-post');
});

it('drops the card of a post purged while the drawer was open', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    $test = Livewire::actingAs($user)
        ->test(PostDrawer::class, ['asOverlay' => true])
        ->call('setSelectedPost', $post->id);

    $post->forceDelete();

    $test->call('deleteSelectedPost')
        ->assertDispatched('post-deleted', postId: $post->id)
        ->assertDispatched('clear-selected-post');
});

it('says a failed deletion about the post it was for, not the next one opened', function () {
    $user = User::factory()->create();
    $someoneElses = Post::factory()->published()->create();
    $next = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostDrawer::class, ['asOverlay' => true])
        ->call('setSelectedPost', $someoneElses->id)
        ->call('deleteSelectedPost')
        ->assertSet('deleteError', __('ui.post.delete_errors.not_allowed'))
        ->call('setSelectedPost', $next->id)
        ->assertSet('deleteError', null);
});

it('sizes the loading skeleton with classes the skeleton takes', function () {
    $post = Post::factory()->published()->create();

    $shapes = collect(livewireDom(Livewire::test(PostDrawer::class, ['postId' => $post->id]))->query("//*[@data-testid='post-drawer-loading']/*"))
        ->map(fn (DOMElement $shape): array => explode(' ', $shape->getAttribute('class')));

    // The picture's block, then two lines: each the size it is given, none the full width a lost size falls back to.
    expect($shapes)->toHaveCount(3)
        ->and($shapes[0])->toContain('h-64')
        ->and($shapes[1])->toContain('w-[70%]')->not->toContain('w-full')
        ->and($shapes[2])->toContain('w-[45%]')->not->toContain('w-full');
});

it('stops hearing the window\'s width once the phone\'s panel leaves the page', function () {
    $html = Livewire::test(PostDrawer::class, ['asOverlay' => true, 'mobileOnly' => true])->html();

    // Heard from init() and let go in destroy(): a page left with the panel open does not close it later.
    expect($html)->toContain("desktop.addEventListener('change', widened)")
        ->toContain("this.stopHearingTheWidth = () => desktop.removeEventListener('change', widened)")
        ->toMatch('/destroy\(\) \{\s*this\.stopHearingTheWidth\?\.\(\)/');
});

it('offers its close button on a post that is gone, the only way out on a phone', function () {
    $post = Post::factory()->published()->create(['title' => 'A post deleted meanwhile']);
    $post->delete();

    Livewire::test(PostDrawer::class, ['asOverlay' => true, 'mobileOnly' => true])
        ->call('setSelectedPost', $post->id)
        ->assertSee(__('ui.post.not_found_title'))
        ->assertSee('data-testid="post-detail-close"', false);
});
