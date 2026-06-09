<?php

use App\Models\Post;

it('renders post card with theme token classes', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('rg-', false);
});

it('post card view uses theme tokens', function () {
    $content = file_get_contents(resource_path('views/components/feed/post-card.blade.php'));

    expect($content)->toContain('rg-');
    expect($content)->not->toContain('bg-white');
    expect($content)->not->toContain('bg-zinc-');
});

it('post drawer view uses theme tokens', function () {
    $content = file_get_contents(resource_path('views/livewire/feed/post-drawer.blade.php'));

    expect($content)->toContain('rg-');
    expect($content)->not->toContain('bg-white');
    expect($content)->not->toContain('bg-zinc-');
});

it('upload form view uses theme tokens', function () {
    $content = file_get_contents(resource_path('views/livewire/feed/upload-post-form.blade.php'));

    expect($content)->toContain('rg-');
    expect($content)->not->toContain('bg-white');
    expect($content)->not->toContain('bg-zinc-');
});

it('report modal view uses theme tokens', function () {
    $content = file_get_contents(resource_path('views/livewire/reports/report-modal.blade.php'));

    expect($content)->toContain('rg-');
    expect($content)->not->toContain('bg-white');
    expect($content)->not->toContain('bg-zinc-');
});

it('renders post show with theme surfaces', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('rg-surface', false);
});
