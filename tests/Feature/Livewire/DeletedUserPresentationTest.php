<?php

use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Enums\CommentStatus;
use App\Http\Resources\Api\UserResource;
use App\Livewire\Feed\PostDrawer;
use App\Livewire\Posts\PostShow;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

/*
 * Public presentation of a deleted author: every surface renders the
 * neutral "Deleted user" label, never the original identity and never the
 * internal tombstone username/email; the author is not linkable and the
 * profile route is gone.
 */

function tombstonedAuthorWithPost(): array
{
    $author = User::factory()->create(['name' => 'Original Author', 'display_name' => 'OrigDisplay']);
    $post = Post::factory()->published()->for($author)->create();
    $comment = Comment::factory()->for($author)->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);

    $originalUsername = $author->username;
    app(AnonymizeUserAccountAction::class)->execute($author);

    return [$author->fresh(), $post, $comment, $originalUsername];
}

it('renders Deleted user on the post page without link, handle or old identity', function () {
    [$author, $post, , $originalUsername] = tombstonedAuthorWithPost();

    Livewire::test(PostShow::class, ['post' => $post])
        ->assertSee('Deleted user')
        ->assertDontSee('Original Author')
        ->assertDontSee('OrigDisplay')
        ->assertDontSee($originalUsername)
        ->assertDontSee('@'.$author->username, false)
        ->assertDontSee('/u/'.$author->username, false);
});

it('renders Deleted user for comments authored by a tombstoned account', function () {
    [$author, $post] = tombstonedAuthorWithPost();

    $html = (string) $this->blade(
        '<x-comments.comment-item :comment="$comment" />',
        ['comment' => $post->comments()->first()],
    );

    expect($html)->toContain('Deleted user')
        ->not->toContain('Original Author')
        ->not->toContain('@'.$author->username)
        ->not->toContain('/u/'.$author->username);
});

it('renders Deleted user on the feed post card without link, handle or old identity', function () {
    [$author, $post, , $originalUsername] = tombstonedAuthorWithPost();

    $html = (string) $this->blade(
        '<x-feed.post-card :post="$post" />',
        ['post' => $post->fresh()->load('user')],
    );

    expect($html)->toContain('Deleted user')
        ->not->toContain('Original Author')
        ->not->toContain($originalUsername)
        ->not->toContain('@'.$author->username)
        ->not->toContain('/u/'.$author->username);
});

it('renders Deleted user in the post drawer without link, handle or old identity', function () {
    [$author, $post, , $originalUsername] = tombstonedAuthorWithPost();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Deleted user')
        ->assertDontSee('Original Author')
        ->assertDontSee($originalUsername)
        ->assertDontSee('@'.$author->username, false)
        ->assertDontSee('/u/'.$author->username, false);
});

it('returns 404 for the tombstone profile route and the pre-deletion username', function () {
    [$author, , , $originalUsername] = tombstonedAuthorWithPost();

    $this->get('/u/'.$author->username)->assertNotFound();
    $this->get('/u/'.$originalUsername)->assertNotFound();
});

it('keeps the post itself publicly accessible after author deletion', function () {
    [, $post] = tombstonedAuthorWithPost();

    $this->get(route('posts.show', $post))->assertOk();
});

it('exposes no username or profile url through the API resource', function () {
    [$author] = tombstonedAuthorWithPost();

    $payload = UserResource::make($author)->resolve();

    expect($payload['username'])->toBeNull()
        ->and($payload['display_name'])->toBe('Deleted user')
        ->and($payload['avatar_url'])->toBeNull()
        ->and($payload['avatar_srcset'])->toBeNull()
        ->and($payload['profile_url'])->toBeNull();
});
