<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;

it('shows saved posts tab content to owner', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $post = Post::factory()->published()->create(['title' => 'Saved In Profile']);

    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);

    $this->actingAs($owner)
        ->get(route('profile.show', ['username' => $owner->username, 'tab' => 'saved']))
        ->assertOk()
        ->assertSee('Saved In Profile');
});

it('does not show saved posts tab content to other users', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $post = Post::factory()->published()->create(['title' => 'Private Saved Post']);
    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);

    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('profile.show', ['username' => $owner->username, 'tab' => 'saved']))
        ->assertOk()
        ->assertDontSee('data-testid="profile-saved-tab"', false);
});

it('does not show saved posts tab content to guests', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $post = Post::factory()->published()->create(['title' => 'Owner Only Post']);
    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);

    $this->get(route('profile.show', ['username' => $owner->username, 'tab' => 'saved']))
        ->assertOk()
        ->assertDontSee('data-testid="profile-saved-tab"', false);
});
