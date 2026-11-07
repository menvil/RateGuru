<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('allows authenticated user to submit a comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Browser Comment Test Post',
    ]);

    actingAs($user);

    visit(route('posts.show', $post))
        ->assertPresent('[data-testid="comment-form"]')
        ->type('[data-testid="comment-body"]', 'Browser smoke test comment')
        ->click('[data-testid="comment-submit"]')
        ->waitForText('Browser smoke test comment')
        ->assertSee('Browser smoke test comment')
        ->assertPresent('[data-testid="comment-item"]');

    $this->assertDatabaseHas('comments', [
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Browser smoke test comment',
    ]);
});

it('closes a comment\'s menu and its report dialog with Escape, and the menu with a reply, leaving the post open', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => true],
    ]);
    $post = Post::factory()->published()->create(['title' => 'A post with comments']);
    Comment::factory()->for($post)->for(User::factory())->create(['body' => 'A comment with a menu']);

    actingAs(User::factory()->create());

    $page = visit(route('feed'))
        ->resize(1440, 900)
        ->click('[data-testid="post-card"]')
        ->waitForText('A comment with a menu');

    waitForPostDetailOverlayOpen($page);

    $comment = '[data-testid="post-detail-overlay"] [data-testid="comment-item"]';
    $menuButton = $comment.' button[aria-controls^="comment-actions-"]';
    $shown = fn (string $selector): string => "getComputedStyle(document.querySelector('{$selector}')).display !== 'none'";
    $menuShown = $shown($comment.' [id^="comment-actions-"]');
    $reportShown = $shown($comment.' [data-testid="report-modal"]');
    $panelOpen = 'document.querySelector(\'[data-testid="post-detail-overlay"]\').classList.contains(\'translate-x-0\')';

    $page->click($menuButton);
    waitForScript($page, $menuShown);
    $page->keys($menuButton, 'Escape');
    waitForScript($page, $menuShown, false);
    $page->assertScript($panelOpen, true);

    $page->click($menuButton)->click($comment.' [data-testid="report-button"]');
    waitForScript($page, $reportShown);
    $page->keys($comment.' [data-testid="report-modal"] [data-testid="modal-close"]', 'Escape');
    waitForScript($page, $reportShown, false);
    $page->assertScript($panelOpen, true)
        ->assertScript($menuShown, true);

    $page->click($comment.' button[wire\\:click^="startReply"]');
    waitForScript($page, $menuShown, false);
});
