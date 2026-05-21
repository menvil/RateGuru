<?php

use App\Actions\Tags\DeleteTagAction;
use App\Exceptions\Tags\CannotDeleteTagException;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

it('deletes an unused tag for an admin', function () {
    $admin = User::factory()->admin()->create();
    $tag = Tag::factory()->create();

    app(DeleteTagAction::class)->handle($admin, $tag);

    $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
});

it('throws when the tag is attached to posts', function () {
    $admin = User::factory()->admin()->create();
    $tag = Tag::factory()->create();

    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    expect(fn () => app(DeleteTagAction::class)->handle($admin, $tag))
        ->toThrow(CannotDeleteTagException::class);

    $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    expect($post->fresh()->tags()->whereKey($tag->id)->exists())->toBeTrue();
});

it('throws when a non-admin attempts to delete a tag', function () {
    $moderator = User::factory()->moderator()->create();
    $tag = Tag::factory()->create();

    expect(fn () => app(DeleteTagAction::class)->handle($moderator, $tag))
        ->toThrow(CannotDeleteTagException::class);

    $this->assertDatabaseHas('tags', ['id' => $tag->id]);
});
