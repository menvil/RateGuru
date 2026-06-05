<?php

it('uses source and category voting components in public views', function () {
    $content = collect([
        resource_path('views/components/feed/post-card.blade.php'),
        resource_path('views/livewire/feed/post-drawer.blade.php'),
        resource_path('views/livewire/posts/post-show.blade.php'),
    ])
        ->map(fn (string $path): string => file_get_contents($path))
        ->implode("\n");

    expect($content)
        ->toContain('<livewire:posts.source-voting')
        ->toContain('<livewire:posts.category-voting')
        ->not->toContain('<livewire:posts.origin-voting')
        ->not->toContain('<livewire:posts.cuisine-voting');
});
