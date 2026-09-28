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
once, to decide where a brand-new identity belongs:

| callback | result |
|---|---|
| identity already linked | that account signs in (lifecycle-gated by `canAuthenticate`, exactly like a password login) |
| unknown identity, email unused | a new account: no password, username from `GenerateUniqueUsernameAction`, `Registered` fired |
| unknown identity, email belongs to an existing account, nobody signed in | a **pending link** in the session; the person is sent to `/login` to prove they own that account |
| unknown identity, a user is signed in and the emails match | the identity is attached to the signed-in account |
| provider shares no email | refused; nothing is created |

A pending link lives in the server-side session for ten minutes and is
consumed once, by the next successful sign-in of any kind — password, or a
provider that is already linked. It only completes when the signed-in
account's email is the email the identity carried; it never moves an
identity between accounts and never replaces an existing one. This is what
lets someone who registered with a password press "Continue with Google"
once, log in with their password once, and use Google directly from then
on — and lets a Google-only account claim a Facebook identity by signing in
with Google.

Email verification stays RateGuru's own: a new Facebook account is
unverified, and a new Google account is verified only for a `@gmail.com`
address or a Google Workspace domain (`email_verified` claim plus a
non-empty `hd`). Everything else goes through the usual verification email.

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
- `resources/views/components/auth/social-buttons.blade.php` — the buttons
  shown on `/login` and `/register`, reusable by any future auth surface.

Tests: `tests/Feature/Auth/Social*Test.php`.
