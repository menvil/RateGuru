<?php

use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\UpdateTagAction;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

it('lets moderators and administrators create tags, with a slug made from the name when none is given', function (Closure $actor) {
    $tag = app(CreateTagAction::class)->handle($actor(), ['name' => 'Street Food', 'slug' => null]);

    expect($tag->fresh())->name->toBe('Street Food')->slug->toBe('street-food')->name_translations->toBeNull();
})->with([
    'an administrator' => [fn (): User => User::factory()->admin()->create()],
    'a moderator' => [fn (): User => User::factory()->moderator()->create()],
]);

it('authorizes tag creation inside the action', function (Closure $actor) {
    expect(fn () => app(CreateTagAction::class)->handle($actor(), ['name' => 'Blocked', 'slug' => 'blocked']))
        ->toThrow(AuthorizationException::class);

    expect(Tag::query()->where('slug', 'blocked')->exists())->toBeFalse();
})->with([
    'a visitor' => [fn (): User => User::factory()->create()],
    'a banned moderator' => [fn (): User => User::factory()->moderator()->banned()->create()],
]);

it('authorizes tag updates inside the action, and leaves the translations alone', function () {
    $translations = [translatedLocales()[0] => 'Original'];
    $tag = Tag::factory()->create(['name' => 'Original', 'slug' => 'original', 'name_translations' => $translations]);

    expect(fn () => app(UpdateTagAction::class)->handle(User::factory()->create(), $tag, ['name' => 'Blocked', 'slug' => 'blocked']))
        ->toThrow(AuthorizationException::class);

    app(UpdateTagAction::class)->handle(User::factory()->moderator()->create(), $tag, ['name' => 'Renamed', 'slug' => '']);

    expect($tag->fresh())->name->toBe('Renamed')->slug->toBe('renamed')->name_translations->toBe($translations);
});
