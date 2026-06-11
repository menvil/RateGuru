<?php

use App\Models\Follow;
use App\Models\User;

it('shows follower and following counts on profile', function () {
    $author = User::factory()->create(['username' => 'counted-author']);

    Follow::factory()->count(2)->create([
        'author_id' => $author->id,
    ]);

    $this->get(route('profile.show', $author->username))
        ->assertOk()
        ->assertSee('data-testid="followers-count"', false)
        ->assertSee('data-testid="following-count"', false);
});

it('follower count reflects actual follower count', function () {
    $author = User::factory()->create(['username' => 'follower-count-author']);

    Follow::factory()->count(3)->create([
        'author_id' => $author->id,
    ]);

    $this->get(route('profile.show', $author->username))
        ->assertOk()
        ->assertSeeInOrder(['data-testid="followers-count"', '3'], false);
});
