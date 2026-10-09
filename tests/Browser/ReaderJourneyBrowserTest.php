<?php

use App\Actions\Moderation\ApprovePostAction;
use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticated;

/*
 * What a reader does with other people's posts, carried across the pages it
 * spans: a vote cast in the feed and changed on the post's page, a vote a
 * guest has to sign in for, an author followed, and a post kept for later.
 */

it('carries a vote cast in the feed to the post’s page, and a vote taken back there to the feed', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Pineapple on pizza',
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);
    $reader = User::factory()->create();

    actingAs($reader);

    visit(route('feed'))
        ->click("@post-upvote-button-{$post->id}")
        ->assertAttribute("@post-upvote-button-{$post->id}", 'aria-pressed', 'true');

    // A vote moves one step at a time: down from an upvote takes it back,
    // and only a second down is a downvote.
    visit(route('posts.show', $post))
        ->assertAttribute("@post-upvote-button-{$post->id}", 'aria-pressed', 'true')
        ->assertSeeIn("@post-upvote-count-{$post->id}", '1')
        ->click("@post-downvote-button-{$post->id}")
        ->assertAttribute("@post-upvote-button-{$post->id}", 'aria-pressed', 'false')
        ->assertAttribute("@post-downvote-button-{$post->id}", 'aria-pressed', 'false')
        ->assertSeeIn("@post-upvote-count-{$post->id}", '0');

    visit(route('feed'))
        ->assertAttribute("@post-upvote-button-{$post->id}", 'aria-pressed', 'false')
        ->click("@post-downvote-button-{$post->id}")
        ->assertAttribute("@post-downvote-button-{$post->id}", 'aria-pressed', 'true');

    visit(route('posts.show', $post))
        ->assertAttribute("@post-downvote-button-{$post->id}", 'aria-pressed', 'true');

    expect(PostVote::query()->where('post_id', $post->id)->sole())
        ->user_id->toBe($reader->id)
        ->type->toBe(VoteType::Down);
});

it('asks a guest to sign in to vote, and counts the vote once they have, on the same page', function () {
    $post = Post::factory()->published()->create(['title' => 'Best ramen in town', 'upvotes_count' => 0]);
    User::factory()->create(['email' => 'guest-voter@rateguru.test']);

    $page = visit(route('feed'))
        ->click("@post-upvote-button-{$post->id}")
        ->assertSeeIn('@post-card-vote-error', 'Sign in to vote.')
        ->click('@header-login-link')
        ->type('@auth-modal-login-email', 'guest-voter@rateguru.test')
        ->type('@auth-modal-login-password', 'password');

    submitAndWaitForNewPage($page, '@auth-modal-login-submit')
        ->assertPathIs('/')
        ->click("@post-upvote-button-{$post->id}")
        ->assertAttribute("@post-upvote-button-{$post->id}", 'aria-pressed', 'true')
        ->assertSeeIn("@post-upvote-count-{$post->id}", '1')
        ->assertMissing('@post-card-vote-error');

    assertAuthenticated();
});

it('follows an author from their post, shows only them in the following feed, and tells the reader when they post again', function () {
    $author = User::factory()->create(['username' => 'chef_ana']);
    $followed = Post::factory()->published()->for($author)->create(['title' => 'Ana’s paella']);
    Post::factory()->published()->create(['title' => 'Somebody else’s soup']);
    $reader = User::factory()->create();

    actingAs($reader);

    visit(route('posts.show', $followed))
        ->click('[data-testid="post-author-follow"] [data-testid="follow-button"]')
        ->assertAttribute('[data-testid="post-author-follow"] [data-testid="follow-button"]', 'aria-pressed', 'true');

    visit(route('feed', ['feed' => 'following']))
        ->assertSeeIn('@post-card-title', 'Ana’s paella')
        ->assertDontSee('Somebody else’s soup');

    // The author's next post is held back for review; the follower hears of
    // it when a moderator lets it through, not before.
    $next = Post::factory()->pending()->for($author)->create(['title' => 'Ana’s churros']);
    app(ApprovePostAction::class)->handle(User::factory()->moderator()->create(), $next);

    visit(route('feed', ['feed' => 'following']))
        ->assertSee('Ana’s churros')
        ->assertSeeIn('@notification-unread-count', '1')
        ->click('[data-testid="notification-bell"] > button')
        ->assertSeeIn('@notification-item', '@chef_ana posted Ana’s churros');
});

it('keeps a post a reader saved on their saved page until they let it go', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);
    $post = Post::factory()->published()->create(['title' => 'Five-minute hummus']);

    actingAs(User::factory()->create());

    visit(route('feed'))
        ->click('@save-post-button')
        ->assertAttribute('@save-post-button', 'aria-pressed', 'true')
        ->click('@header-user-menu-trigger')
        ->click('@nav-saved-posts')
        ->assertPathIs(route('saved-posts.index', absolute: false))
        ->assertSeeIn('@post-card-title', 'Five-minute hummus')
        ->click('@save-post-button')
        ->assertAttribute('@save-post-button', 'aria-pressed', 'false');

    visit(route('saved-posts.index'))->assertVisible('@saved-posts-empty-state');

    expect($post->savedByUsers()->count())->toBe(0);
});
