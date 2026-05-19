<?php

use App\Livewire\Comments\CommentForm;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('can render comment form component for authenticated user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('data-testid="comment-form"', false);
});

it('renders login prompt for guest', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentForm::class, ['postId' => $post->id])
        ->assertSee('Log in to comment')
        ->assertDontSee('data-testid="comment-form"', false);
});

it('creates comment from form submit', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->set('body', 'Looks delicious.')
        ->call('submit')
        ->assertDispatched('comment-created');

    $this->assertDatabaseHas('comments', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'body' => 'Looks delicious.',
    ]);
});
