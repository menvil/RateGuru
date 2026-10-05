<?php

use App\Actions\Auth\ClaimAccountWithVerifiedEmailAction;
use App\Data\Auth\SocialIdentity;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * Claiming an account treats the provider's confirmation of an email address as
 * proof of ownership, then verifies the account's email and revokes its
 * credentials — clearing the password, rotating the session generation, deleting
 * sessions and password-reset tokens.
 *
 * The account is found by email BEFORE the row lock is taken. A profile email
 * change landing in that window used to leave this verifying the NEW address, and
 * deleting ITS password-reset tokens, on the strength of a proof about the old
 * one. Proof of one address must never verify another.
 */
function claimIdentityFor(string $email): SocialIdentity
{
    return new SocialIdentity(
        provider: SocialProvider::Google,
        providerUserId: 'google-'.md5($email),
        email: $email,
        name: 'Claimer',
        nickname: null,
        emailVerifiedByProvider: true,
    );
}

it('refuses a claim when the account changed email after it was found', function () {
    // The stale instance is the whole point: this is the object the caller
    // resolved by email before the transaction, exactly as
    // ResolveSocialLoginAction does.
    $user = User::factory()->unverified()->create(['email' => 'proved@example.test']);
    $stale = User::query()->findOrFail($user->id);

    // The profile moves to a different address in the window.
    User::query()->whereKey($user->id)->update(['email' => 'moved@example.test']);

    expect(fn () => app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($stale, claimIdentityFor('proved@example.test')))
        ->toThrow(SocialAuthenticationException::class);

    // And nothing was done on the strength of the stale proof: the new address is
    // untouched, unverified, and the password still stands.
    $fresh = $user->fresh();

    expect($fresh->email)->toBe('moved@example.test');
    expect($fresh->email_verified_at)->toBeNull();
    expect($fresh->password)->not->toBeNull();
    expect($fresh->socialAccounts()->count())->toBe(0);
});

it('claims the account when the address is still the one that was proved', function () {
    // The ordinary path, so the guard above is a guard and not a blanket refusal.
    $user = User::factory()->unverified()->create(['email' => 'proved@example.test']);

    $claim = app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($user, claimIdentityFor('proved@example.test'));

    expect($claim)->not->toBeNull();

    $fresh = $user->fresh();

    expect($fresh->email_verified_at)->not->toBeNull();
    expect($fresh->password)->toBeNull();
    expect($fresh->socialAccounts()->count())->toBe(1);
});

it('compares the address the way the rest of the social flow does', function () {
    // Normalised, not raw: the provider's address is already trimmed and
    // lowercased, and a stored address differing only in case is the same
    // address. Refusing that would break a legitimate claim.
    $user = User::factory()->unverified()->create(['email' => 'Proved@Example.test']);

    $claim = app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($user, claimIdentityFor('proved@example.test'));

    expect($claim)->not->toBeNull();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('revokes a provider link the squatter left on an account they had a password for', function () {
    // The takeover case. A password on an unconfirmed address means somebody chose
    // a secret for an address they did not control, so every credential on the
    // account is theirs — and a provider link was the one this used to leave
    // behind, because ResolveSocialLoginAction signs in whatever account a known
    // identity points at.
    //
    // The factory gives this user a password, which is what makes it a takeover
    // rather than a confirmation; the no-password case is the next test.
    $user = User::factory()->unverified()->create(['email' => 'proved@example.test']);

    // The creator's own link, on a different provider so it cannot be mistaken
    // for the one being claimed with.
    $creatorLink = $user->socialAccounts()->create([
        'provider' => SocialProvider::Facebook,
        'provider_user_id' => 'facebook-creator',
        'provider_email' => 'creator@example.test',
    ]);

    $claim = app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($user, claimIdentityFor('proved@example.test'));

    expect($claim->secured)->toBeTrue();

    // The creator's link is gone; the claimer's is the only one left.
    expect(SocialAccount::query()->whereKey($creatorLink->getKey())->exists())->toBeFalse();

    $remaining = $user->fresh()->socialAccounts;

    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->provider)->toBe(SocialProvider::Google);
});

it('keeps an existing link when the unconfirmed account never had a password', function () {
    // The deliberate opposite, and the reason the revocation above is conditional.
    // An unconfirmed account with no password was created THROUGH a provider, so
    // an existing link is most likely the same person arriving on a second
    // provider. This operation only confirms such an account; deleting that link
    // would lock out the person it belongs to.
    $user = User::factory()->unverified()->withoutPassword()->create(['email' => 'proved@example.test']);

    $existing = $user->socialAccounts()->create([
        'provider' => SocialProvider::Facebook,
        'provider_user_id' => 'facebook-same-person',
        'provider_email' => 'proved@example.test',
    ]);

    $claim = app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($user, claimIdentityFor('proved@example.test'));

    expect($claim->secured)->toBeTrue();
    expect($claim->passwordRemoved)->toBeFalse();

    // Confirmed, and both links intact.
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    expect(SocialAccount::query()->whereKey($existing->getKey())->exists())->toBeTrue();
    expect($user->fresh()->socialAccounts)->toHaveCount(2);
});

it('keeps existing links when the account was already confirmed', function () {
    // `secured` is false for a confirmed account: nobody's ownership is in
    // question, so this is an ordinary link and no revocation belongs here.
    // Deleting a confirmed user's other providers would be a different and much
    // worse bug than the one above.
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $user->markEmailAsVerified();

    $existing = $user->socialAccounts()->create([
        'provider' => SocialProvider::Facebook,
        'provider_user_id' => 'facebook-owner',
        'provider_email' => 'owner@example.test',
    ]);

    $claim = app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($user, claimIdentityFor('owner@example.test'));

    expect($claim->secured)->toBeFalse();
    expect(SocialAccount::query()->whereKey($existing->getKey())->exists())->toBeTrue();
    expect($user->fresh()->socialAccounts)->toHaveCount(2);
});
