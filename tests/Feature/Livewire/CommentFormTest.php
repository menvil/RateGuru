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

it('does not let a guest create a comment via the submit action', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentForm::class, ['postId' => $post->id])
        ->set('body', 'Sneaky guest comment')
        ->call('submit')
        ->assertOk()
        ->assertHasErrors('body')
        ->assertNotDispatched('comment-created');

    $this->assertDatabaseCount('comments', 0);
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

it('renders comment textarea', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->assertSee('name="body"', false)
        ->assertSee('Write a comment')
        ->assertSee('maxlength="1000"', false);
});

it('does not render textarea for guest', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentForm::class, ['postId' => $post->id])
        ->assertDontSee('name="body"', false);
});

it('renders comment submit button', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->assertSee('Post comment')
        ->assertSee('wire:submit', false);
});

it('renders comment validation error on empty submit', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->set('body', '')
        ->call('submit')
        ->assertSee('data-testid="comment-body-error"', false);

    $this->assertDatabaseCount('comments', 0);
});

it('clears comment body after successful submit', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->set('body', 'Looks delicious.')
        ->call('submit')
        ->assertSet('body', '');
});

it('keeps comment body after validation failure', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->set('body', str_repeat('a', 1001))
        ->call('submit')
        ->assertSet('body', str_repeat('a', 1001));
});
