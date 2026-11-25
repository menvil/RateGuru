<?php

use App\Support\Languages\LanguageProfile;

/*
 * The language a post is written in: its text counts three times, its author twice, each comment once and each
 * vote a little, all the votes together no more than the author; the post is in the language holding at least half.
 */

it('names no language when nothing tells one', function () {
    $profile = new LanguageProfile;

    expect($profile->language())->toBeNull()
        ->and($profile->share())->toBeNull();
});

it('takes the text over an author of another language', function () {
    $profile = (new LanguageProfile)->text('ru', 1.0)->author('en');

    expect($profile->language())->toBe('ru')
        ->and($profile->share())->toBe(0.6);
});

it('lets the author win over a short text in another language', function () {
    // "Barcelona 2024" read as Spanish at three quarters: 2.25 against the author's 2, and no half for either.
    $profile = (new LanguageProfile)->text('es', 0.75)->author('de');

    expect($profile->language())->toBe('es')
        ->and((new LanguageProfile)->text('es', 0.5)->author('de')->language())->toBe('de');
});

it('counts a text that tells no language as nothing', function () {
    expect((new LanguageProfile)->text(null, 1.0)->author('bg')->language())->toBe('bg')
        ->and((new LanguageProfile)->text('ru', 0.0)->author('bg')->language())->toBe('bg');
});

it('names none when no language holds half the weight', function () {
    $profile = (new LanguageProfile)->text('ru', 1.0)->author('bg')->comment('uk', 1.0, 'uk')->comment('uk', 1.0, 'uk');

    expect($profile->language())->toBeNull();
});

it('counts a comment by its text, and an emoji-only one at half to its writer', function () {
    $profile = (new LanguageProfile)->author('en')
        ->comment('ru', 1.0, 'en')
        ->comment('ru', 1.0, 'en')
        ->comment(null, 0.0, 'ru')
        ->comment(null, 0.0, 'ru');

    // en 2, ru 1 + 1 + 0.5 + 0.5 = 3.
    expect($profile->language())->toBe('ru')
        ->and($profile->share())->toBe(0.6);
});

it('gives the rest of a short comment\'s weight to its writer at half', function () {
    // ru 0.5, and the other half at half to the writer: bg 0.25.
    $profile = (new LanguageProfile)->comment('ru', 0.5, 'bg');

    expect($profile->language())->toBe('ru')
        ->and($profile->share())->toBe(0.667);
});

it('caps all the votes together at the author\'s weight', function () {
    $profile = (new LanguageProfile)->text('de', 1.0)->author('de');

    foreach (range(1, 100) as $vote) {
        $profile->vote('en');
    }

    // de 5, en capped at 2: a popular post keeps its own language.
    expect($profile->language())->toBe('de')
        ->and($profile->share())->toBe(0.714);
});

it('lets a few votes tip a post with no other signal', function () {
    $profile = (new LanguageProfile)->vote('ru')->vote('ru')->vote('en');

    expect($profile->language())->toBe('ru')
        ->and($profile->share())->toBe(0.667);
});

it('shares the capped weight among the voters\' languages in proportion', function () {
    $profile = (new LanguageProfile)->author('pl');

    foreach (range(1, 30) as $vote) {
        $profile->vote('ru');
    }

    foreach (range(1, 10) as $vote) {
        $profile->vote('en');
    }

    // pl 2, ru 1.5, en 0.5.
    expect($profile->language())->toBe('pl')
        ->and($profile->share())->toBe(0.5);
});

it('ignores signals with no language', function () {
    $profile = (new LanguageProfile)->author(null)->vote(null)->comment(null, 0.0, null)->text('fr', 1.0);

    expect($profile->language())->toBe('fr')
        ->and($profile->share())->toBe(1.0);
});
