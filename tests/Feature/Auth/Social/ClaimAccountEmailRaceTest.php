<?php

use App\Actions\Auth\ClaimAccountWithVerifiedEmailAction;
use App\Data\Auth\SocialIdentity;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Models\PasswordResetToken;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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

    // The profile moves to a different address in the window, and that address has
    // a password-reset link outstanding — the credential a claim revokes.
    User::query()->whereKey($user->id)->update(['email' => 'moved@example.test']);
    PasswordResetToken::create([
        'email' => 'moved@example.test',
        'token' => Hash::make('reset-for-the-moved-address'),
        'created_at' => now(),
    ]);

    expect(fn () => app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($stale, claimIdentityFor('proved@example.test')))
        ->toThrow(SocialAuthenticationException::class);

    // And nothing was done on the strength of the stale proof: the new address is
    // untouched, unverified, the password still stands — and above all the reset
    // link outstanding for the address nobody proved is still outstanding. That
    // token is the sharpest consequence of getting this wrong: deleting it would
    // let whoever controls the OLD address take the NEW one over at leisure.
    $fresh = $user->fresh();

    expect($fresh->email)->toBe('moved@example.test');
    expect($fresh->email_verified_at)->toBeNull();
    expect($fresh->password)->not->toBeNull();
    expect($fresh->socialAccounts()->count())->toBe(0);
    expect(PasswordResetToken::query()->where('email', 'moved@example.test')->exists())
        ->toBeTrue('the reset token of the address that was never proved must survive a refused claim');
});

it('claims the account when the address is still the one that was proved', function () {
    // The ordinary path, so the guard above is a guard and not a blanket refusal.
    $user = User::factory()->unverified()->create(['email' => 'proved@example.test']);

    // An outstanding reset link for the address being proved. The claim revokes
    // it: a link anyone had requested before the owner proved the address is a way
    // back in, and this is the half of the invariant the refusal case above cannot
    // show.
    PasswordResetToken::create([
        'email' => 'proved@example.test',
        'token' => Hash::make('reset-for-the-proved-address'),
        'created_at' => now(),
    ]);

    $claim = app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($user, claimIdentityFor('proved@example.test'));

    expect($claim)->not->toBeNull();

    $fresh = $user->fresh();

    expect($fresh->email_verified_at)->not->toBeNull();
    expect($fresh->password)->toBeNull();
    expect($fresh->socialAccounts()->count())->toBe(1);
    expect(PasswordResetToken::query()->where('email', 'proved@example.test')->exists())
        ->toBeFalse('a successful claim revokes the reset links of the address it verified');
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

it('keeps an existing link on a passwordless account only when it proves the same address', function () {
    // This test used to assert the opposite, on the reasoning that an unconfirmed
    // account with no password was created THROUGH a provider, so an existing link
    // was most likely the same person arriving on a second provider.
    //
    // That reasoning was exactly backwards. RegisterSocialUserAction creates an
    // account from an address the provider has NOT confirmed — which is precisely
    // why the account is unconfirmed and this operation is reachable — and stores
    // the link anyway. "No password" therefore described the case where nobody had
    // proved the address, and the old rule treated it as proof. The consequence was
    // a working sign-in left behind for whoever created the account.
    //
    // What keeps a link now is evidence of the same address, and nothing else.
    $user = User::factory()->unverified()->withoutPassword()->create(['email' => 'proved@example.test']);

    $proves = $user->socialAccounts()->create([
        'provider' => SocialProvider::Facebook,
        'provider_user_id' => 'facebook-same-person',
        'provider_email' => 'proved@example.test',
        'provider_email_verified' => true,
    ]);

    $claim = app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($user, claimIdentityFor('proved@example.test'));

    expect($claim->secured)->toBeTrue();
    expect($claim->passwordRemoved)->toBeFalse();

    // Confirmed, and the link that can prove this address is intact.
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    expect(SocialAccount::query()->whereKey($proves->getKey())->exists())->toBeTrue();
    expect($user->fresh()->socialAccounts)->toHaveCount(2);
});

it('revokes a passwordless account\'s existing link when it proves nothing', function () {
    // The same shape as above, with the only difference that matters: the provider
    // never confirmed the address this link records. Absence of a password buys it
    // nothing.
    $user = User::factory()->unverified()->withoutPassword()->create(['email' => 'proved@example.test']);

    $provesNothing = $user->socialAccounts()->create([
        'provider' => SocialProvider::Facebook,
        'provider_user_id' => 'facebook-whoever-made-this',
        'provider_email' => 'proved@example.test',
        'provider_email_verified' => false,
    ]);

    $claim = app(ClaimAccountWithVerifiedEmailAction::class)
        ->execute($user, claimIdentityFor('proved@example.test'));

    expect($claim->secured)->toBeTrue();
    expect($claim->passwordRemoved)->toBeFalse();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    expect(SocialAccount::query()->whereKey($provesNothing->getKey())->exists())->toBeFalse();

    // Only the identity that just proved the address remains.
    $remaining = $user->fresh()->socialAccounts;
    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->provider)->toBe(SocialProvider::Google);
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
