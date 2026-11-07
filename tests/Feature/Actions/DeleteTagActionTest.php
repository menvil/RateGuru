<?php

use App\Actions\Tags\DeleteTagAction;
use App\Enums\UserRole;
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

    try {
        app(DeleteTagAction::class)->handle($admin, $tag);
        $this->fail('Expected CannotDeleteTagException.');
    } catch (CannotDeleteTagException $e) {
        expect($e->reason)->toBe(CannotDeleteTagException::REASON_USED_BY_POSTS);
    }

    $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    expect($post->fresh()->tags()->whereKey($tag->id)->exists())->toBeTrue();
});

it('throws a not-allowed reason when a non-admin attempts to delete a tag', function () {
    $moderator = User::factory()->moderator()->create();
    $tag = Tag::factory()->create();

    try {
        app(DeleteTagAction::class)->handle($moderator, $tag);
        $this->fail('Expected CannotDeleteTagException.');
    } catch (CannotDeleteTagException $e) {
        expect($e->reason)->toBe(CannotDeleteTagException::REASON_NOT_ALLOWED);
    }

    $this->assertDatabaseHas('tags', ['id' => $tag->id]);
});

it('reads the admin again in its transaction, so one demoted meanwhile cannot delete', function () {
    $admin = User::factory()->admin()->create();
    $tag = Tag::factory()->create();

    // Demoted after the instance this call holds was loaded.
    User::query()->whereKey($admin->id)->update(['role' => UserRole::User]);

    try {
        app(DeleteTagAction::class)->handle($admin, $tag);
        $this->fail('Expected CannotDeleteTagException.');
    } catch (CannotDeleteTagException $e) {
        expect($e->reason)->toBe(CannotDeleteTagException::REASON_NOT_ALLOWED);
    }

    $this->assertDatabaseHas('tags', ['id' => $tag->id]);
});

it('refuses an admin demoted meanwhile even when the tag is already gone', function () {
    $admin = User::factory()->admin()->create();
    $tag = Tag::factory()->create();

    // Demoted, and the tag deleted by someone else, after both instances this
    // call holds were loaded.
    User::query()->whereKey($admin->id)->update(['role' => UserRole::User]);
    Tag::query()->whereKey($tag->id)->delete();

    try {
        app(DeleteTagAction::class)->handle($admin, $tag);
        $this->fail('Expected CannotDeleteTagException.');
    } catch (CannotDeleteTagException $e) {
        expect($e->reason)->toBe(CannotDeleteTagException::REASON_NOT_ALLOWED);
    }
});
