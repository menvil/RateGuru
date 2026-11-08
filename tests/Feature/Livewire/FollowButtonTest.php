<?php

use App\Livewire\Follows\FollowButton;
use App\Models\Follow;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

it('renders follow button for authenticated user viewing another author', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test(FollowButton::class, ['author' => $author])
        ->assertSee('data-testid="follow-button"', false);
});

it('toggles follow state from follow button', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test(FollowButton::class, ['author' => $author])
        ->call('toggle')
        ->assertSet('isFollowing', true);

    $this->assertDatabaseHas('follows', [
        'follower_id' => $viewer->id,
        'author_id' => $author->id,
    ]);
});

it('does not render follow button for self', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FollowButton::class, ['author' => $user])
        ->assertDontSee('data-testid="follow-button"', false);
});

it('shows login required message for guest', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $author = User::factory()->create();

    Livewire::test(FollowButton::class, ['author' => $author])
        ->call('toggle')
        ->assertSet('isFollowing', false)
        ->assertSee('Log in to follow authors.');
});

it('does not render follow button when feature flag is disabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => false]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test(FollowButton::class, ['author' => $author])
        ->assertDontSee('data-testid="follow-button"', false);
});

it('mounts with following state true when already following', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $viewer->id,
        'author_id' => $author->id,
    ]);

    Livewire::actingAs($viewer)
        ->test(FollowButton::class, ['author' => $author])
        ->assertSet('isFollowing', true);
});

it('tells a viewer whose own account may not follow so, neutrally, without blaming the author', function (string $state) {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    Livewire::actingAs(User::factory()->{$state}()->create())
        ->test(FollowButton::class, ['author' => User::factory()->create()])
        ->call('toggle')
        ->assertSet('isFollowing', false)
        ->assertSet('message', __('follows.cannot_follow'))
        // In English, as it is said: about the viewer, not the author.
        ->assertSeeHtml('<span class="text-xs text-rg-muted empty:sr-only" role="status" data-testid="follow-button-message">You cannot follow authors right now.</span>');
})->with(['limited', 'banned', 'shadowbanned']);

it('still says the author cannot be followed when it is the author', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    Livewire::actingAs(User::factory()->create())
        ->test(FollowButton::class, ['author' => User::factory()->banned()->create()])
        ->call('toggle')
        ->assertSet('message', __('follows.cannot_follow_author'))
        ->assertSeeHtml('<span class="text-xs text-rg-muted empty:sr-only" role="status" data-testid="follow-button-message">This author cannot be followed.</span>');
});

it('keeps its status region in the page before there is anything to say, so what comes is heard', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $html = Livewire::actingAs(User::factory()->create())
        ->test(FollowButton::class, ['author' => User::factory()->create()])
        ->html();

    expect($html)->toMatch('/<span[^>]*role="status"[^>]*data-testid="follow-button-message"><\/span>/')
        // Out of the layout while empty, but still in the accessibility tree: not display:none.
        ->toMatch('/class="[^"]*\bempty:sr-only\b[^"]*"[^>]*role="status"/')
        ->not->toContain('empty:hidden');
});
