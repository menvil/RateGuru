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
No token, avatar or name is stored after the callback. The provider's email
is kept on the identity only to show which account is connected — see
[Connected accounts](#connected-accounts).

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
per user — both enforced by unique indexes). The provider email decides
where a brand-new identity belongs; it never finds an account for an identity
that is already linked.

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
| a connection started from the Connected accounts card, finished by the same signed-in account | the identity is attached to that account, whatever its email — unless that email is **another account's** address, which is refused |
| a sign-in round trip whose callback finds the session signed in | refused: nothing is connected and the session never switches accounts |
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

## Connected accounts

The profile's **Connected accounts** card lists Google and Facebook with the
connected account's email and the date it was connected.

### Sign-in and connecting are different intents

Signing in with a provider and connecting one to an account share the same
redirect and callback, so the server records which of the two a round trip
is before it leaves for the provider:

- **OAuth state** (Socialite, bound to the session, never stateless) proves
  that a callback belongs to the round trip this session started. It says
  nothing about *why* the round trip was started, or for which account.
- **`SocialLinkContext`** is that second half. Pressing **Connect** posts to
  `profile.connected-accounts.store`, which writes the context — the
  signed-in user's id, the provider and the time, nothing else — into the
  server-side session right before the provider redirect. It lives ten
  minutes and is consumed by the first callback, whatever the outcome.
  `GET /auth/{provider}` never writes one and voids any abandoned one: that
  round trip is always a sign-in.
- The callback **never decides it is connecting because the session is
  signed in**. It connects only when the context exists, is fresh, names the
  callback's provider, and the session is signed in to the account that
  started it. A context that fails any of that — expired, another provider,
  another account, signed out — refuses the callback (`link_expired`): no
  sign-in, no registration, no link to anyone. Without a context, a
  callback that finds the session signed in is refused too
  (`already_signed_in`). Both refusals discard the OAuth state without
  asking the provider anything, so the same callback cannot be replayed
  into a sign-in later.

### Connecting and disconnecting

- Every outcome of a connection — success or refusal — lands back on the
  card (`ConnectedAccountsResponse`), with refusals in their own
  `connectedAccounts` error bag; a refused connection whose account has
  signed out lands on the login page instead.
- The connected account may use a **different email** than the RateGuru
  account: signing in to the provider during a connection this account
  started is the proof of ownership. It is refused when its email is another RateGuru account's
  address — that address belongs with the other account — and, as always,
  when the identity is already linked elsewhere or the account already has
  another identity of that provider.
- **Disconnect** asks for confirmation and deletes the identity. The last way
  into an account is never removed: an account without a password keeps at
  least one provider (`SignInMethods`), decided on the locked account row so
  two disconnects cannot race past it. The card explains why the button is
  missing instead of offering it.
- The **provider email** (`social_accounts.provider_email`) is refreshed on
  every sign-in through the identity, so the card shows the address the
  provider currently reports, or says that none was shared. It is never used
  to find an account and is deleted with the row.
- A **security email** goes to the account's own address whenever a provider
  is attached to an existing account — from the card, by signing in with a
  confirmed email, or by completing a pending link — and whenever one is
  disconnected. Both are queued and dispatched only after the change commits,
  in the recipient's language. A brand-new account and a repeat of a
  connection already made send nothing.

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
- `App\Support\Auth\SocialLinkContext` — the server-side connection
  intent the callback checks before it attaches anything.
- `App\Http\Controllers\Profile\ConnectedAccountController`,
  `App\Actions\Auth\UnlinkSocialAccountAction`,
  `App\Queries\UserConnectedAccountsQuery` and
  `resources/views/profile/partials/connected-accounts.blade.php` — the
  Connected accounts card; `SocialAccountConnectedNotification` and
  `SocialAccountDisconnectedNotification` — the security emails.
- `resources/views/components/auth/social-buttons.blade.php` — the two
  provider buttons, shown below the email/password form on `/login`,
  `/register` and in both states of the [authentication modal](auth-modal.md).

Tests: `tests/Feature/Auth/Social*Test.php`,
`tests/Feature/Profile/ConnectedAccountsTest.php`.
