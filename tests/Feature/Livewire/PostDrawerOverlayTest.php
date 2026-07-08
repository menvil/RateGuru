<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use Livewire\Livewire;

it('does not render overlay chrome by default', function () {
    Livewire::test(PostDrawer::class)
        ->assertDontSee('data-testid="post-detail-overlay"', false)
        ->assertDontSee('data-testid="post-detail-overlay-host"', false);
});

it('mounts the overlay host closed when asOverlay is enabled with no post selected', function () {
    Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->assertSee('data-testid="post-detail-overlay-host"', false)
        ->assertDontSee('data-testid="post-detail-overlay"', false);
});

it('opens and loads the post when a select-post event is received', function () {
    $post = Post::factory()->published()->create(['title' => 'Overlay Target Post']);

    Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->dispatch('select-post', postId: $post->id)
        ->assertSet('postId', $post->id)
        ->assertSet('isOpen', true)
        ->assertSee('data-testid="post-detail-overlay"', false)
        ->assertSee('Overlay Target Post');
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

    Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->dispatch('select-post', postId: $post->id)
        ->assertSet('isOpen', true)
        ->dispatch('clear-selected-post')
        ->assertSet('isOpen', false)
        ->assertDontSee('data-testid="post-detail-overlay"', false);
});

it('starts the panel off-screen only on the render that just opened it, not on later renders', function () {
    // Regression test: the off-screen starting class must be present in the exact
    // response that transitions the panel from closed to open (so Alpine's x-init has
    // something to slide in from), but must NOT reappear on a later render that happens
    // while already open (e.g. switching posts) — otherwise Livewire's morph would keep
    // snapping the already-open panel back off-screen. This previously broke because the
    // flag controlling it was reset inside render() itself, which is not guaranteed to
    // run exactly once per interaction; dehydrate() is used instead.
    $first = Post::factory()->published()->create(['title' => 'First Post']);
    $second = Post::factory()->published()->create(['title' => 'Second Post']);

    $test = Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->dispatch('select-post', postId: $first->id);

    preg_match('/data-testid="post-detail-overlay"[^>]*class="([^"]*)"/', $test->html(), $openMatch);
    expect($openMatch[1] ?? '')->toContain('translate-x-full');
    expect($test->get('justOpened'))->toBeFalse();

    $test->dispatch('select-post', postId: $second->id);

    preg_match('/data-testid="post-detail-overlay"[^>]*class="([^"]*)"/', $test->html(), $switchMatch);
    expect($switchMatch[1] ?? '')->not->toContain('translate-x-full');
});
