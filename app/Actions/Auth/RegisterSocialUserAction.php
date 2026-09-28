<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\CreatesUserWithUniqueUsername;
use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Data\Auth\SocialIdentity;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use Illuminate\Auth\Events\Registered;
use InvalidArgumentException;

/**
 * Creates the account behind a social identity nobody has seen before.
 *
 * The User and its SocialAccount land in one transaction, so a crash or a
 * lost race can never leave a person without their identity or an identity
 * without its person. Mirrors RegisterUserAction wherever the two overlap —
 * the same username generator, the same locale capture, the same Registered
 * event — and differs only where a social account genuinely is different:
 * there is no password, and the email may start out verified when the
 * provider is the authority for it.
 *
 * Does not sign the user in; the caller owns the session.
 */
final class RegisterSocialUserAction
{
    use CreatesUserWithUniqueUsername;

    public function __construct(
        private readonly GenerateUniqueUsernameAction $generateUniqueUsername,
        private readonly LocaleManager $locales,
    ) {}

    public function execute(SocialIdentity $identity): User
    {
        if ($identity->email === null) {
            throw new InvalidArgumentException('A social account cannot be registered without an email address.');
        }

        $name = $identity->displayName();

        $user = $this->createUserWithUniqueUsername(
            $this->generateUniqueUsername,
            $name,
            function (string $username) use ($identity, $name): User {
                $user = User::create([
                    'name' => $name,
                    'username' => $username,
                    'email' => $identity->email,
                    // The language the person signed up in — the same rule,
                    // for the same reason, as RegisterUserAction.
                    'locale' => $this->locales->normalize(app()->getLocale()),
                    // A social-only account has no password at all. Never a
                    // random placeholder: a credential nobody knows is still a
                    // credential, and password reset exists for the day the
                    // person wants one.
                    'password' => null,
                ]);

                if ($identity->emailVerifiedByProvider) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                SocialAccount::create([
                    'user_id' => $user->id,
                    'provider' => $identity->provider,
                    'provider_user_id' => $identity->providerUserId,
                ]);

                return $user;
            },
        );

        event(new Registered($user));

        return $user;
    }
}
