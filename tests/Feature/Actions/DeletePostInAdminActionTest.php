<?php

use App\Actions\Posts\DeletePostInAdminAction;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

it('allows an administrator to soft-delete a post from admin', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();

    app(DeletePostInAdminAction::class)->handle($admin, $post);

    $this->assertSoftDeleted('posts', ['id' => $post->id]);
});

it('rejects non-administrators at the action boundary', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    expect(fn () => app(DeletePostInAdminAction::class)->handle($moderator, $post))
        ->toThrow(AuthorizationException::class);

    $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
});
