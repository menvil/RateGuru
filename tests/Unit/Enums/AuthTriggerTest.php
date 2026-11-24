<?php

use App\Enums\AuthTrigger;

/*
 * What opened the sign-in dialog — or showed a sign-up form of its own — on
 * a visit, as the registration funnel splits the people who opened it.
 */

it('names every opener of the dialog in at most sixteen characters, with a label for the funnel', function () {
    expect(array_map(fn (AuthTrigger $trigger): string => $trigger->value, AuthTrigger::cases()))->toBe([
        'header_signup', 'header_login', 'upload', 'vote', 'rating', 'comment_vote', 'game', 'game_form', 'page', 'other',
    ])->and(array_filter(AuthTrigger::cases(), fn (AuthTrigger $trigger): bool => strlen($trigger->value) > 16))->toBe([])
        ->and(array_map(fn (AuthTrigger $trigger): string => $trigger->label(), AuthTrigger::cases()))->toBe([
            'Sign up in the header', 'Log in in the header', 'Upload a post', 'A vote on a post', 'A rating on a post', 'A vote on a comment',
            'A game', 'A game’s own sign-up form', 'The sign-in or sign-up page', 'Anything else',
        ]);
});

it('reads an opener it does not know as anything else', function () {
    expect(AuthTrigger::fromSignal('vote'))->toBe(AuthTrigger::Vote)
        ->and(AuthTrigger::fromSignal('a_new_button'))->toBe(AuthTrigger::Other)
        ->and(AuthTrigger::fromSignal(null))->toBe(AuthTrigger::Other);
});
