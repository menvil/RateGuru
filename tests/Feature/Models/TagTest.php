<?php

use App\Models\Tag;

it('returns the translation for the current app locale when present', function () {
    app()->setLocale('ru');

    $tag = Tag::factory()->create([
        'name' => 'Pizza',
        'name_translations' => ['ru' => 'Пицца'],
    ]);

    expect($tag->translatedName())->toBe('Пицца');
});

it('falls back to the plain name when the current locale has no translation entry', function () {
    app()->setLocale('bg');

    $tag = Tag::factory()->create([
        'name' => 'Pizza',
        'name_translations' => ['ru' => 'Пицца'],
    ]);

    expect($tag->translatedName())->toBe('Pizza');
});

it('falls back to the plain name when name_translations is null', function () {
    app()->setLocale('ru');

    $tag = Tag::factory()->create([
        'name' => 'Pizza',
        'name_translations' => null,
    ]);

    expect($tag->translatedName())->toBe('Pizza');
});
