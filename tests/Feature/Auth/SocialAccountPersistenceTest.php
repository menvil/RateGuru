<?php

use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * The social_accounts table itself: what it stores, what it refuses, and
 * that anonymization takes every identity with it.
 */

it('stores only the identity and casts the provider to the enum', function () {
    $user = User::factory()->create();

    $account = SocialAccount::factory()->for($user)->facebook()->create(['provider_user_id' => 'fb-1']);
    $fresh = $account->fresh();

    expect($fresh->provider)->toBe(SocialProvider::Facebook)
        ->and($fresh->provider_user_id)->toBe('fb-1')
        ->and($fresh->user->is($user))->toBeTrue()
        ->and($user->socialAccounts()->count())->toBe(1)
        ->and(array_keys($fresh->getAttributes()))
        ->toBe(['id', 'user_id', 'provider', 'provider_user_id', 'created_at', 'updated_at']);
});

it('enforces one account per provider identity', function () {
    SocialAccount::factory()->google()->create(['provider_user_id' => 'g-1']);

    expect(fn () => SocialAccount::factory()->google()->create(['provider_user_id' => 'g-1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('enforces one identity per provider per account', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);

    expect(fn () => SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-2']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows Google and Facebook side by side on one account', function () {
    $user = User::factory()->create();

    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'same-subject']);
    SocialAccount::factory()->for($user)->facebook()->create(['provider_user_id' => 'same-subject']);

    expect($user->socialAccounts()->count())->toBe(2);
});

it('removes every social identity when the account is anonymized', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);
    SocialAccount::factory()->for($user)->facebook()->create(['provider_user_id' => 'fb-1']);
    $other = User::factory()->create();
    SocialAccount::factory()->for($other)->google()->create(['provider_user_id' => 'g-2']);

    app(AnonymizeUserAccountAction::class)->execute($user);

    expect($user->fresh()->isTombstoned())->toBeTrue()
        ->and(SocialAccount::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(SocialAccount::query()->where('user_id', $other->id)->count())->toBe(1);
});

it('keeps social identities through living sanctions', function (string $state) {
    $user = User::factory()->{$state}()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);

    expect($user->socialAccounts()->count())->toBe(1)
        ->and($user->canAuthenticate())->toBeTrue();
})->with(['limited', 'banned', 'shadowbanned']);
