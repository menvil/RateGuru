<?php

use App\Enums\SignupRefusal;

/*
 * Why a sign-up was refused, as the registration funnel counts it: what the
 * screening found folded into a limit, the challenge or the rest; a taken
 * address apart from any other mistake in the form; every reason a visit met
 * kept together in one mask. Never a value the form held.
 */

it('reads what the screening found as the refusals it stands for', function (array $reasons, array $refusals) {
    expect(SignupRefusal::fromScreening($reasons))->toBe($refusals);
})->with([
    'an hourly limit' => [['hourly_limit'], [SignupRefusal::Throttled]],
    'a daily limit' => [['daily_limit'], [SignupRefusal::Throttled]],
    'no challenge, past its limit' => [['limit_without_challenge'], [SignupRefusal::Throttled]],
    'the limits out of reach' => [['limits_unavailable'], [SignupRefusal::Throttled]],
    'the challenge failed' => [['challenge_failed'], [SignupRefusal::Captcha]],
    'the honeypot' => [['honeypot'], [SignupRefusal::Screened]],
    'no stamp on the form' => [['no_form_stamp'], [SignupRefusal::Screened]],
    'filled in too fast' => [['filled_too_fast'], [SignupRefusal::Screened]],
    'a throwaway address' => [['disposable_email'], [SignupRefusal::Screened]],
    'a Gmail alias' => [['gmail_alias'], [SignupRefusal::Screened]],
    'something new' => [['a_reason_to_come'], [SignupRefusal::Screened]],
    'several at once' => [['honeypot', 'challenge_failed', 'hourly_limit', 'filled_too_fast'], [SignupRefusal::Captcha, SignupRefusal::Screened, SignupRefusal::Throttled]],
    'nothing' => [[], []],
]);

it('takes a taken address for one and every other mistake in the form for invalid', function (array $failed, array $refusals) {
    expect(SignupRefusal::fromFailedRules($failed))->toBe($refusals);
})->with([
    'a taken address' => [['email' => ['Unique' => ['users', 'email']]], [SignupRefusal::EmailTaken]],
    'an address that is no address' => [['email' => ['Email' => []]], [SignupRefusal::Invalid]],
    'a short password' => [['password' => ['Min' => [8]]], [SignupRefusal::Invalid]],
    'both' => [['email' => ['Unique' => ['users', 'email']], 'password' => ['Confirmed' => []]], [SignupRefusal::EmailTaken, SignupRefusal::Invalid]],
    'nothing failed' => [[], []],
]);

it('takes an address another sign-up took first for a taken one, and anything else the account write refused for other', function () {
    expect(SignupRefusal::fromAccountWrite(['email' => ['That address is taken.']]))->toBe([SignupRefusal::EmailTaken])
        ->and(SignupRefusal::fromAccountWrite(['name' => ['No free name.']]))->toBe([SignupRefusal::Other])
        ->and(SignupRefusal::fromAccountWrite([]))->toBe([SignupRefusal::Other]);
});

it('keeps every reason a visit met in one mask and reads them back in order', function () {
    $mask = SignupRefusal::mask([SignupRefusal::Throttled, SignupRefusal::EmailTaken]);

    expect($mask)->toBe(17)
        ->and(SignupRefusal::fromMask($mask))->toBe([SignupRefusal::EmailTaken, SignupRefusal::Throttled])
        ->and(SignupRefusal::fromMask($mask | SignupRefusal::Throttled->bit()))->toBe([SignupRefusal::EmailTaken, SignupRefusal::Throttled])
        ->and(SignupRefusal::fromMask(0))->toBe([])
        ->and(array_map(fn (SignupRefusal $refusal): int => $refusal->bit(), SignupRefusal::cases()))->toBe([1, 2, 4, 8, 16, 32]);
});

it('names every reason for the funnel', function () {
    expect(array_map(fn (SignupRefusal $refusal): string => $refusal->label(), SignupRefusal::cases()))->toBe([
        'Address already taken', 'Something in the form was wrong', 'Failed the challenge', 'Stopped as a likely bot', 'Too many sign-ups from the address', 'Something else',
    ]);
});
