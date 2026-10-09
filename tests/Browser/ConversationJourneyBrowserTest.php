<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * A conversation under a post, between the people having it: a comment
 * reaching the post's author, a reply, and a comment taken back. The person
 * acting is switched between the steps.
 */

it('brings a reader’s comment to the post’s author, whose notification leads back to it', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create(['title' => 'Sourdough, day three']);
    $reader = User::factory()->create(['username' => 'crumb_fan']);

    actingAs($reader);

    visit(route('posts.show', $post))
        ->type('@comment-body', 'That crumb looks perfect.')
        ->click('@comment-submit')
        ->assertSeeIn('@comment-item', 'That crumb looks perfect.');

    actingAs($author);

    visit(route('feed'))
        ->assertSeeIn('@notification-unread-count', '1')
        ->click('[data-testid="notification-bell"] > button')
        ->assertSeeIn('@notification-item', '@crumb_fan commented on your post')
        ->click('[data-testid="notification-item"] a')
        ->assertPathIs(route('posts.show', $post, absolute: false))
        ->assertSeeIn('@comment-item', 'That crumb looks perfect.');
});

it('keeps a reply in place under a comment its writer deleted, behind a tombstone', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create(['title' => 'Which knife for tomatoes?']);
    $reader = User::factory()->create();
    $comment = Comment::factory()->for($post)->for($reader)->create(['body' => 'A bread knife, honestly.']);

    actingAs($author);

    visit(route('posts.show', $post))
        ->click("#comment-{$comment->id} button[wire\\:click^=\"startReply\"]")
        ->type('[data-testid="reply-form"] input[name="replyBody"]', 'Tried it, works great.')
        ->click('[data-testid="reply-form"] button[type="submit"]')
        ->assertSeeIn('@comment-replies', 'Tried it, works great.');

    actingAs($reader);

    $page = visit(route('posts.show', $post));

    // Deleting asks through the browser's own confirm(), which a test cannot
    // answer; what is under test is what happens once the person agrees.
    $page->script('() => { window.confirm = () => true; return true; }');

    $page->click("#comment-{$comment->id} button[aria-controls=\"comment-actions-{$comment->id}\"]")
        ->click("#comment-{$comment->id} button[wire\\:click^=\"deleteComment\"]")
        ->assertSeeIn('@comment-tombstone', '[comment deleted]')
        ->assertDontSee('A bread knife, honestly.')
        ->assertSeeIn('@comment-replies', 'Tried it, works great.');
});
