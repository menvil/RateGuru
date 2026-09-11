<?php

use App\Enums\SocialProvider;

it('contains exactly google and facebook', function () {
    expect(SocialProvider::values())->toBe(['google', 'facebook'])
        ->and(SocialProvider::Google->value)->toBe('google')
        ->and(SocialProvider::Facebook->value)->toBe('facebook');
});

it('validates only its own values, case-sensitively', function () {
    expect(SocialProvider::isValid('google'))->toBeTrue()
        ->and(SocialProvider::isValid('facebook'))->toBeTrue()
        ->and(SocialProvider::isValid('Google'))->toBeFalse()
        ->and(SocialProvider::isValid('github'))->toBeFalse()
        ->and(SocialProvider::isValid(''))->toBeFalse();
});

it('labels each provider by its public name', function () {
    expect(SocialProvider::Google->label())->toBe('Google')
        ->and(SocialProvider::Facebook->label())->toBe('Facebook');
});

it('requests only the minimum scopes for each provider', function () {
    expect(SocialProvider::Google->scopes())->toBe(['openid', 'profile', 'email'])
        ->and(SocialProvider::Facebook->scopes())->toBe(['email']);
});
