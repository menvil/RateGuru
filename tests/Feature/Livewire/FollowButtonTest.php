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
