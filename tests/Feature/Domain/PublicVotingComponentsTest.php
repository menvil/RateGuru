<?php

it('uses the generic rating voting component in public views', function () {
    $content = collect([
        resource_path('views/components/feed/post-card.blade.php'),
        resource_path('views/livewire/feed/post-drawer.blade.php'),
        resource_path('views/livewire/posts/post-show.blade.php'),
    ])
        ->map(fn (string $path): string => file_get_contents($path))
        ->implode("\n");

    expect($content)
        ->toContain('<livewire:voting.rating-voting')
        ->not->toContain('<livewire:posts.source-voting')
        ->not->toContain('<livewire:posts.category-voting');
});
