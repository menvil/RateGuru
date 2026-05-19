<?php

use App\Enums\CommentStatus;
use App\Livewire\Comments\CommentsSection;
use App\Models\Comment;
use App\Models\Post;
use Livewire\Livewire;

it('can render comments section component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('data-testid="comments-section"', false);
});

it('renders visible comments and hides hidden or deleted comments', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Looks delicious.',
        'status' => CommentStatus::Visible,
    ]);

    Comment::factory()->for($post)->create([
        'body' => 'Hidden comment',
        'status' => CommentStatus::Hidden,
    ]);

    $deleted = Comment::factory()->for($post)->create([
        'body' => 'Deleted comment',
        'status' => CommentStatus::Visible,
    ]);
    $deleted->delete();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Looks delicious.')
        ->assertDontSee('Hidden comment')
        ->assertDontSee('Deleted comment');
});
