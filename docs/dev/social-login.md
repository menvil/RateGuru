# Social sign-in (Google, Facebook)

RateGuru signs people in with Google and Facebook through
[Laravel Socialite](https://laravel.com/docs/socialite). The integration is
session-based like the rest of authentication: OAuth state is verified
against the session, nothing is ever `stateless()`, and a successful callback
ends in exactly the same place a password login does.

## Configuration

| variable | what it is |
|---|---|
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | an OAuth 2.0 client of type "Web application" in Google Cloud Console |
| `FACEBOOK_CLIENT_ID` / `FACEBOOK_CLIENT_SECRET` | the App ID and App Secret of a Facebook app with the "Facebook Login" product |

Register these callbacks with the provider, on the `APP_URL` of the
environment (the callback path is fixed in `config/services.php` and
Socialite resolves it against the current application URL, so no environment
ever carries another one's callback):

```text
/auth/google/callback
/auth/facebook/callback
```

The four keys are listed, blank, in `.env.example` and in both server
environment templates (`infrastructure/templates/environment/`). Each
environment gets its own OAuth apps and its own values, set in that target's
`shared/.env`; the nightly backup carries them from there, so a host recovery
restores them with the rest of the environment file.

Nothing else is requested. Google is asked for `openid profile email`,
Facebook for the `email` permission with the `name` and `email` fields only.
No token, avatar, name or provider email is stored after the callback.

### Local development

- **Google** accepts `http://localhost:8000/auth/google/callback` as an
  authorized redirect URI, so a local `.env` with a test client works end to
  end. Add your own Google account as a test user while the OAuth consent
  screen is in "Testing".
- **Facebook** only completes a real login against an app in development
  mode with the callback on the app's configured domain, and with the
  signing-in person listed as a developer or test user of that app. The real
  Facebook integration is therefore verified on staging with that app
  configuration, not locally.

Automated tests need no credentials at all: they use `Socialite::fake()`
and never contact a provider. Real credentials are never committed; the
`.env.example` values stay empty.

## How an identity maps to an account

The external identity is `provider + provider_user_id`, stored in
`social_accounts` (one row per identity, at most one identity per provider
per user — both enforced by unique indexes). The provider email is only used
to decide where a brand-new identity belongs.

A provider **vouches** for an email when it has confirmed it: Google for any
`@gmail.com` address and for any address it reports with
`email_verified: true`; Facebook for every address it reports, because it
only completes a registration after the address is confirmed.

| callback | result |
|---|---|
| identity already linked | that account signs in (lifecycle-gated by `canAuthenticate`, exactly like a password login) |
| unknown identity, email unused | a new account: no password, username from `GenerateUniqueUsernameAction`, `Registered` fired; the email starts confirmed when the provider vouches for it |
| unknown identity, provider vouches, the account's email is **confirmed** | signs straight in and links the provider; the password and everything else stay |
| unknown identity, provider vouches, the account's email is **unconfirmed** | signs straight in and links the provider, and the account is taken over from whoever created it: email confirmed, password removed, every other session and "remember me" ended, reset links voided, and the owner is told to set a new password |
| unknown identity, provider does **not** vouch, email belongs to an account | a **pending link** in the session; the person signs in to that account once (password or another provider) and the identity is linked |
| unknown identity, a user is signed in and the emails match | the identity is attached to the signed-in account |
| provider shares no email | refused; nothing is created |

The takeover case exists because an unconfirmed account may have been
registered by someone other than the mailbox owner, waiting for the owner
to arrive. `ClaimAccountWithVerifiedEmailAction` does it in one transaction,
after every linking rule has passed. Other sessions end through the
account's **session generation** (`users.session_generation`): every sign-in
records it in the session, the claim starts a new one, and
`EnsureSessionGenerationIsCurrent` signs any older session out on its next
request — whatever the session driver.

A pending link lives in the server-side session for ten minutes and is
consumed once, by the next successful sign-in of any kind. It only completes
when the signed-in account's email is the email the identity carried; it
never moves an identity between accounts and never replaces an existing one.

Email verification stays RateGuru's own where the provider does not vouch
for the address: such a new account receives the usual verification email.

An account created through a provider has no password. The profile page
offers it **Set a password** instead of the change-password form: it emails
the ordinary password-reset link to the account's own address, and the reset
page works while signed in, so only whoever reads that mailbox can set the
password. Deleting such an account is confirmed with its email address
instead of a password.

Account deletion (`AnonymizeUserAccountAction`) deletes every social
identity along with the rest of the private account state — see
[user lifecycle](../architecture/user-lifecycle.md).

## Where the code lives

- `App\Enums\SocialProvider` — the closed provider list; routes and the
  controller accept nothing else.
- `App\Support\Auth\SocialProviderGateway` — the only Socialite call site:
  scopes, fields, redirect, and the translation of expected OAuth failures
  (cancelled, refused, invalid state) into `SocialAuthenticationException`.
  Anything unexpected still reaches the normal exception handler and Sentry.
- `App\Actions\Auth\ResolveSocialLoginAction` — the account resolution
  above; `RegisterSocialUserAction`, `LinkSocialAccountAction`,
  `StorePendingSocialLinkAction` and `CompletePendingSocialLinkAction` are
  the individual writes.
- `resources/views/components/auth/social-buttons.blade.php` — the two
  provider buttons, shown below the email/password form on `/login`,
  `/register` and in both states of the [authentication modal](auth-modal.md).

Tests: `tests/Feature/Auth/Social*Test.php`.
