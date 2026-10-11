<?php

use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * Moderation from both ends: what a reader does on the site, what a
 * moderator then does in the panel or in place, and what everyone sees
 * afterwards. Each step is a different component, and often a different
 * person, so the person acting is switched between the steps.
 */

/**
 * Runs a Filament row action the way a moderator does: presses it on the
 * row, writes the note its confirmation asks for when one is given, and
 * confirms. Returns once the confirmation has closed, which it does after the
 * action has run.
 */
function confirmPanelRowAction(mixed $page, string $label, ?string $note = null): mixed
{
    $modalOpen = '[...document.querySelectorAll(".fi-modal-window")].some((modal) => modal.checkVisibility())';

    $page->click($label);
    waitForScript($page, $modalOpen);

    if ($note !== null) {
        $page->type('.fi-modal-window textarea:visible', $note);
    }

    $page->click('.fi-modal-window button[type="submit"]:visible');
    waitForScript($page, $modalOpen, false);

    return $page;
}

it('takes a reported post down from the report in the panel, for everyone', function () {
    $post = Post::factory()->published()->create(['title' => 'Buy cheap followers here']);
    $reader = User::factory()->create();
    $moderator = User::factory()->moderator()->create();

    actingAs($reader);

    visit(route('feed'))
        ->click("@post-actions-menu-{$post->id}")
        ->click('@report-button')
        ->click('[data-testid="report-modal"] input[name="reason"][value="spam"]')
        ->type('#report-message', 'Advertising, not a post.')
        ->click('@submit-report')
        ->assertVisible('@report-success');

    actingAs($moderator);

    // The report reached the queue, pointing at the post.
    $panel = visit('/admin/reports')->assertSee('Buy cheap followers here');
    confirmPanelRowAction($panel, 'Hide target', 'Spam.');
    confirmPanelRowAction($panel, 'Resolve', 'Hidden as spam.')
        ->assertDontSee('Hide target');

    $report = Report::query()->sole();

    expect($post->fresh()->status)->toBe(PostStatus::Hidden)
        ->and($report->status)->toBe(ReportStatus::Resolved)
        ->and($report->resolved_by)->toBe($moderator->id);

    // The reader who reported it no longer finds it, in the feed or at its
    // address.
    actingAs($reader);

    visit(route('feed'))->assertDontSee('Buy cheap followers here');
    visit(route('posts.show', $post))->assertSee('404');
});

it('takes a post down from its card in the feed, and the feed lets go of it at once', function () {
    $post = Post::factory()->published()->create(['title' => 'Off-topic rant']);
    Post::factory()->published()->create(['title' => 'A perfectly fine post']);

    actingAs(User::factory()->moderator()->create());

    visit(route('feed'))
        ->click("@post-actions-menu-{$post->id}")
        ->click("[data-testid=\"post-card\"]:has([data-testid=\"post-actions-menu-{$post->id}\"]) [data-testid=\"moderation-hide\"]")
        ->click('[role="dialog"] [data-testid="hide-confirmation-confirm"]:visible')
        ->assertDontSee('Off-topic rant')
        ->assertSee('A perfectly fine post');

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);

    actingAs(User::factory()->create());

    visit(route('posts.show', $post))->assertSee('404');
});

it('publishes a held-back post when a moderator approves it in the panel, and tells its author', function () {
    $author = User::factory()->create(['trust_level' => 0]);
    $post = Post::factory()->pending()->for($author)->create(['title' => 'Grandma’s dumplings']);

    actingAs($author);

    visit(route('feed'))->assertDontSee('Grandma’s dumplings');

    actingAs(User::factory()->moderator()->create());

    confirmPanelRowAction(visit('/admin/posts'), 'Approve');

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    actingAs($author);

    visit(route('feed'))
        ->assertSeeIn('@post-card-title', 'Grandma’s dumplings')
        ->assertSeeIn('@notification-unread-count', '1')
        ->click('[data-testid="notification-bell"] > button')
        ->assertSeeIn('@notification-item', 'Your post was approved')
        ->click('[data-testid="notification-item"] a')
        ->assertPathIs(route('posts.show', $post, absolute: false))
        ->assertSee('Grandma’s dumplings');
});

it('bans the author of a reported comment from the report, and the author can no longer comment', function () {
    $post = Post::factory()->published()->create(['title' => 'Weekend plans']);
    $troll = User::factory()->create(['name' => 'Tom Troll']);
    Comment::factory()->for($post)->for($troll)->create(['body' => 'Everyone here is an idiot.']);

    actingAs(User::factory()->create());

    // The post's own report form is on this page too, hidden. Only the open
    // one is the comment's, and a click on the hidden one would wait for it
    // to show for as long as the run lasts.
    visit(route('posts.show', $post))
        ->click('[data-testid="comment-item"] button[aria-controls^="comment-actions-"]')
        ->click('[data-testid="comment-report"] [data-testid="report-button"]')
        ->click('[data-testid="report-modal"]:visible input[name="reason"][value="offensive"]')
        ->click('[data-testid="report-modal"]:visible [data-testid="submit-report"]')
        ->assertVisible('[data-testid="report-modal"]:visible [data-testid="report-success"]');

    actingAs(User::factory()->admin()->create());

    confirmPanelRowAction(visit('/admin/reports'), 'Ban target author', 'Abusive comments.');

    expect($troll->fresh()->status)->toBe(UserStatus::Banned);

    actingAs($troll->fresh());

    visit(route('posts.show', $post))
        ->assertVisible('@account-restriction-notice')
        ->type('@comment-body', 'Let me back in.')
        ->click('@comment-submit')
        ->assertSeeIn('@comment-body-error', 'User is not allowed to comment.');

    expect(Comment::query()->where('body', 'Let me back in.')->exists())->toBeFalse();
});
