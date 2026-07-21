<?php

use App\Models\Post;

it('voting component views use theme token classes', function (string $path) {
    $content = file_get_contents(resource_path($path));

    expect($content)->toContain('rg-');
})->with([
    'views/components/voting/rating-options.blade.php',
]);

it('voting components do not use raw background colors', function (string $path) {
    $content = file_get_contents(resource_path($path));

    expect($content)->not->toContain('bg-white');
    expect($content)->not->toContain('bg-zinc-');
    expect($content)->not->toContain('bg-gray-');
})->with([
    'views/components/voting/rating-options.blade.php',
]);

it('renders rating voting on post show page', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('rg-', false);
});
