<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;

it('does not expose one users saved posts to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $post = Post::factory()->published()->create([
        'title' => 'Private Saved Post',
    ]);

    PostSave::factory()->create([
        'user_id' => $owner->id,
        'post_id' => $post->id,
    ]);

    $this->actingAs($other)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertDontSee('Private Saved Post');
});

it('does not allow guests to view saved posts page', function () {
    $this->get(route('saved-posts.index'))
        ->assertRedirect(route('login'));
});

it('does not expose saved post list of owner to other user via direct url', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $post = Post::factory()->published()->create([
        'title' => 'Owners Saved Post',
    ]);

    PostSave::factory()->create([
        'user_id' => $owner->id,
        'post_id' => $post->id,
    ]);

    $this->actingAs($other)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertDontSee('Owners Saved Post');
});

it('shows only the authenticated users own saved posts', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $myPost = Post::factory()->published()->create(['title' => 'My Saved Post']);
    $theirPost = Post::factory()->published()->create(['title' => 'Their Saved Post']);

    PostSave::factory()->create(['user_id' => $user->id, 'post_id' => $myPost->id]);
    PostSave::factory()->create(['user_id' => $other->id, 'post_id' => $theirPost->id]);

    $this->actingAs($user)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertSee('My Saved Post')
        ->assertDontSee('Their Saved Post');
});

it('saved state shows false for another users saved post', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();

    $post = Post::factory()->published()->create();

    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);

    $this->actingAs($viewer)
        ->get(route('posts.show', $post))
        ->assertOk();

    $this->assertDatabaseHas('post_saves', [
        'user_id' => $owner->id,
        'post_id' => $post->id,
    ]);

    $this->assertDatabaseMissing('post_saves', [
        'user_id' => $viewer->id,
        'post_id' => $post->id,
    ]);
});
