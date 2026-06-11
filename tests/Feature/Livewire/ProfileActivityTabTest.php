<?php

use App\Models\RatingVote;
use App\Models\User;

it('shows rating activity tab to profile owner', function () {
    $user = User::factory()->create([
        'username' => 'ivan',
        'rating_activity_visibility' => 'private',
    ]);

    $this->actingAs($user)
        ->get(route('profile.show', ['username' => $user->username, 'tab' => 'activity']))
        ->assertOk()
        ->assertSee('data-testid="profile-activity-tab"', false);
});

it('shows activity to owner even when private', function () {
    $user = User::factory()->create([
        'username' => 'ivan',
        'rating_activity_visibility' => 'private',
    ]);

    RatingVote::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('profile.show', ['username' => $user->username, 'tab' => 'activity']))
        ->assertOk()
        ->assertSee('data-testid="profile-activity-tab"', false);
});

it('hides private rating activity from other users', function () {
    $user = User::factory()->create([
        'username' => 'ivan',
        'rating_activity_visibility' => 'private',
    ]);

    RatingVote::factory()->create(['user_id' => $user->id]);

    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('profile.show', ['username' => $user->username, 'tab' => 'activity']))
        ->assertOk()
        ->assertSee('data-testid="profile-activity-private"', false);
});

it('shows public activity to other users when visibility is public', function () {
    $user = User::factory()->create([
        'username' => 'ivan',
        'rating_activity_visibility' => 'public',
    ]);

    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('profile.show', ['username' => $user->username, 'tab' => 'activity']))
        ->assertOk()
        ->assertSee('data-testid="profile-activity-tab"', false)
        ->assertDontSee('data-testid="profile-activity-private"', false);
});
