<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;

it('shows public posts in profile posts tab', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    Post::factory()->for($user)->published()->create([
        'title' => 'Visible Profile Post',
    ]);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('Visible Profile Post');
});

it('shows empty state when user has no public posts', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('No published posts yet');
});

it('does not show hidden posts in posts tab', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    Post::factory()->for($user)->published()->create(['title' => 'Public Post']);
    Post::factory()->for($user)->create(['title' => 'Hidden Post', 'status' => PostStatus::Hidden]);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('Public Post')
        ->assertDontSee('Hidden Post');
});

it('does not show rejected posts in posts tab', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    Post::factory()->for($user)->create(['title' => 'Rejected Post', 'status' => PostStatus::Rejected]);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertDontSee('Rejected Post');
});
