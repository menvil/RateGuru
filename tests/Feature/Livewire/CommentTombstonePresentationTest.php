<?php

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Enums\CommentStatus;
use App\Livewire\Comments\CommentsSection;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Queries\Comments\CommentListQuery;
use Livewire\Livewire;

/*
 * Public rendering of structural tombstones: neutral placeholder only —
 * no body, identity, avatar, profile link or actions may reach the HTML.
 */

function tombstoneThread(string $kind): array
{
    $author = User::factory()->create(['name' => 'Parent Author', 'username' => 'parent_author']);
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->for($author)->create([
        'post_id' => $post->id,
        'status' => CommentStatus::Visible,
        'body' => 'Sensitive original body',
    ]);
    $reply = Comment::factory()->create([
        'post_id' => $post->id,
        'parent_id' => $parent->id,
        'status' => CommentStatus::Visible,
        'body' => 'Reply that must survive',
    ]);

    if ($kind === 'deleted') {
        app(DeleteCommentAction::class)->handle($author, $parent);
    } else {
        app(HideCommentAction::class)->handle(User::factory()->moderator()->create(), $parent);
    }

    return [$post, $parent, $reply];
}

it('renders an author-deleted parent as a neutral tombstone above its reply', function () {
    [$post, $parent, $reply] = tombstoneThread('deleted');

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('[comment deleted]')
        ->assertSee('Reply that must survive')
        ->assertDontSee('Sensitive original body')
        ->assertDontSee('Parent Author')
        ->assertDontSee('@parent_author', false)
        ->assertDontSee('/u/parent_author', false)
        ->assertDontSee('comment-voting-'.$parent->id, false)
        ->assertDontSee('hideComment('.$parent->id.')', false)
        ->assertDontSee('deleteComment('.$parent->id.')', false)
        ->assertDontSee('startReply('.$parent->id.')', false);
});

it('renders a moderator-hidden parent as a removed-by-moderator tombstone', function () {
    [$post, $parent] = tombstoneThread('hidden');

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('[comment removed by moderator]')
        ->assertSee('Reply that must survive')
        ->assertDontSee('Sensitive original body')
        ->assertDontSee('Parent Author')
        ->assertDontSee('@parent_author', false)
        ->assertDontSee('startReply('.$parent->id.')', false);
});

it('renders nothing at all for removed leaves', function (string $kind) {
    $author = User::factory()->create();
    $post = Post::factory()->published()->create();
    $leaf = Comment::factory()->for($author)->create([
        'post_id' => $post->id,
        'status' => CommentStatus::Visible,
        'body' => 'Leaf body to disappear',
    ]);

    if ($kind === 'deleted') {
        app(DeleteCommentAction::class)->handle($author, $leaf);
    } else {
        app(HideCommentAction::class)->handle(User::factory()->moderator()->create(), $leaf);
    }

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertDontSee('Leaf body to disappear')
        ->assertDontSee('[comment deleted]')
        ->assertDontSee('[comment removed by moderator]');
})->with(['deleted', 'hidden']);

it('counts renderable roots so a tombstone-only thread still paginates', function () {
    [$post] = tombstoneThread('deleted');

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSet('postId', $post->id);

    expect(app(CommentListQuery::class)->countRenderableTopLevel($post->id))->toBe(1);
});

it('keeps normal comments fully rendered after the tombstone changes', function () {
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->create([
        'post_id' => $post->id,
        'status' => CommentStatus::Visible,
        'body' => 'Perfectly normal comment',
    ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Perfectly normal comment')
        ->assertSee('comment-item');
});
