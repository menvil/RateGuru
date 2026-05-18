<?php

use App\Livewire\Posts\PostShow;
use App\Models\Post;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('can render post show component for published post', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
    ]);

    Livewire::test(PostShow::class, ['post' => $post])
        ->assertStatus(200)
        ->assertSee('Homemade Carbonara');
});

it('does not resolve an unpublished post', function () {
    $post = Post::factory()->hidden()->create();

    expect(fn () => Livewire::test(PostShow::class, ['post' => $post]))
        ->toThrow(ModelNotFoundException::class);
});
