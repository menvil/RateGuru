<?php

use App\Models\Post;
use Illuminate\Support\Facades\Blade;

it('share buttons lay provider links out as brand-colored chips in a share-sheet grid', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)
        ->toContain('grid-cols-4')
        ->toContain('sm:grid-cols-5')
        ->toContain('background-color: #1877F2')
        ->toContain('rounded-full')
        ->toContain('text-rg-text2');
});
