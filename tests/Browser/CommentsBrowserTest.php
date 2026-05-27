<?php

use App\Models\Post;
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
