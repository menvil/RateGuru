<?php

use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
use App\Models\User;

it('allows a user to delete their own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    app(DeletePostAction::class)->handle($user, $post);

    $this->assertSoftDeleted('posts', ['id' => $post->id]);
    expect(Post::withTrashed()->find($post->id)->status)->toBe(PostStatus::Deleted);
});

it('does not allow users to delete someone elses post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    expect(fn () => app(DeletePostAction::class)->handle($user, $post))
        ->toThrow(CannotDeletePostException::class);

    $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
});
