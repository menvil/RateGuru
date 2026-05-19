<?php

use App\Enums\CommentStatus;
use App\Livewire\Comments\CommentsSection;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
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

it('refreshes comments section after comment-created event', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Fresh comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->dispatch('comment-created', postId: $post->id)
        ->assertSee('Fresh comment')
        ->assertSee('data-testid="comment-item"', false);
});

it('allows owner to delete own comment from comments section', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()->for($user)->for($post)->create([
        'body' => 'My comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($user)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Delete')
        ->call('deleteComment', $comment->id)
        ->assertDontSee('My comment')
        ->assertDispatched('comment-deleted');

    $this->assertSoftDeleted('comments', ['id' => $comment->id]);
});

it('does not render delete button for non owner', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $post = Post::factory()->published()->create();

    Comment::factory()->for($owner)->for($post)->create([
        'body' => 'Owner comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($viewer)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Owner comment')
        ->assertDontSee('Delete');
});

it('allows moderator to hide comment from comments section', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()->for($post)->create([
        'body' => 'Bad comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($moderator)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Hide')
        ->call('hideComment', $comment->id)
        ->assertDontSee('Bad comment')
        ->assertDispatched('comment-hidden');

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
});

it('does not render hide button for normal user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Normal visible comment',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($user)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Normal visible comment')
        ->assertDontSee('Hide');
});

it('renders comments empty state when no visible comments exist', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->create([
        'body' => 'Hidden comment',
        'status' => CommentStatus::Hidden,
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('No comments yet')
        ->assertDontSee('Hidden comment');
});

it('has comments loading state markup', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('data-testid="comments-loading"', false)
        ->assertSee('wire:loading', false);
});

it('does not 500 when a guest invokes deleteComment', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()->for($owner)->for($post)->create([
        'body' => 'Survives guest delete attempt',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->call('deleteComment', $comment->id)
        ->assertOk();

    $this->assertNotSoftDeleted('comments', ['id' => $comment->id]);
});

it('does not 500 when a guest invokes hideComment', function () {
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()->for($post)->create([
        'body' => 'Survives guest hide attempt',
        'status' => CommentStatus::Visible,
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->call('hideComment', $comment->id)
        ->assertOk();

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
});

it('targets refreshComments in the loading state', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('wire:target="deleteComment,hideComment,refreshComments"', false);
});
