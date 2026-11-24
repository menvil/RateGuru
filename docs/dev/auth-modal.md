# Authentication modal

Guests sign in, sign up and ask for a password reset link in one dialog
that opens over the page they are on. `/login`, `/register` and
`/forgot-password` stay fully functional: they are the fallback for a
direct URL, a new tab, a browser without JavaScript and an error page.

The modal is presentation only. It posts to the same `POST /login`,
`POST /register` and `POST /forgot-password`, links to the same
`/auth/google` and `/auth/facebook`, and runs the same controllers and
actions as the standalone pages. It adds no endpoint, no JSON API and no
second way to authenticate.

## Opening it

`<x-auth.modal />` is rendered once, in the application layout, for guests.
Anything that wants a guest to authenticate only names a mode:

```html
<button x-on:click="$dispatch('open-auth-modal', { mode: 'register' })">
```

`rgOpenAuthModal($event, mode, fallbackUrl)` wraps the same event for links
and buttons that must keep working where the dialog is absent: a modified
click is left to the browser, and a page without a dialog navigates to the
standalone page instead. The header links and the guest upload button use
it; later guest actions (comments, follows) can do the same.

A Livewire component whose server refuses a guest opens it from that
answer instead, with `App\Livewire\Concerns\OpensAuthModal`:
`$this->openAuthModal()` sends the same event, in login mode unless it
names another. Post, comment and rating votes do: the refusal is still
shown next to the buttons, and the dialog opens over the page, so the
attempt reaches the server and is still recorded in the security log.

The dialog opens only when signing in could let the vote through. A guest's
vote gets the answer a member would get instead, with no dialog, when:

- the post is no longer published (post votes, rating votes);
- the post still exists but no longer takes rating votes (rating votes);
- the rating option is switched off or archived (rating votes);
- the comment is hidden or deleted, or its post no longer takes votes
  (comment votes).

### What opened it

The registration funnel counts what opened the dialog (`App\Enums\AuthTrigger`,
`docs/architecture/traffic.md`, "Registration steps"). The event's detail
names it beside the mode: `{ mode, trigger }`. `rgOpenAuthModal` reads it from
the element clicked (`data-auth-trigger`: `header_signup`, `header_login` and
`upload` in the header); `openAuthModal($mode, $trigger)` sends it from a
component (`vote`, `rating`, `comment_vote`, `game`). The dialog keeps the
opener while it stays open — a switch between its states names none — and
tells the tracker the state it shows and the opener each time it shows a form
(`rgTrafficSignal('signup_form', { mode, trigger })`). The standalone pages
name themselves (`data-rg-traffic-mode`, `data-rg-traffic-trigger="page"`).
An opener the server doesn't know counts as `other`.

The security log (`App\Support\Observability\GuestVoteAttemptLog`) is
best-effort and normally limited: one visitor address writes at most 30
attempts a minute, then one line saying it was cut off, and nothing more
until the minute is over. The limit is counted in the cache, and fails open:
while the cache cannot be reached, every attempt is written. A failure to
write the log never changes the refusal.

## Three states, no tabs

Login, registration and the reset-link request are three states of the one
dialog (`App\Enums\AuthModalMode`). The link at the bottom switches between
login and registration without navigation, reload or URL change; "Forgot
your password?" in the login form, and "Set a password" in the notice about
a switched-off provider, switch to the reset-link state the same way. Login
and registration are the same composition, `<x-auth.panel>`, in a fixed
order:

1. the email/password form (`<x-auth.login-form>` / `<x-auth.register-form>`)
2. its primary button
3. the `or` divider
4. Google, then Facebook (`<x-auth.social-buttons>`)
5. the switch to the other state

The reset-link state is that panel without providers: its form
(`<x-auth.forgot-password-form>`), its primary button, and the way back to
login.

The standalone pages render the very same panel, so the two surfaces cannot
drift apart. Email/password stays the primary path; the providers come
after it and read "Log in with …" in both states, because the provider flow
decides on its own whether it signs in, signs up or links.

All forms are in the DOM at once, so ids are prefixed by surface and mode
(`modal-login-email`, `page-register-name`, `modal-forgot-password-email`, …).

## Coming back to the same page

A modal submission marks itself and says where it was opened:

| field | value |
|---|---|
| `_auth_surface` | `modal` |
| `_auth_mode` | `login`, `register` or `forgot-password` |
| `_auth_return_to` | the current path and query string |

The return path is a claim from the browser, never a destination.
`App\Support\Auth\AuthReturnUrl` is the only thing that may turn it into a
redirect, and it keeps local paths only: one leading slash, no scheme, host,
backslash, control character or whitespace. Anything else becomes `/`.

`App\Support\Auth\AuthSurfaceContext` then decides where a response goes:

| outcome | standalone page | modal |
|---|---|---|
| success | as before (`intended`, or the dashboard) | the page it was opened on, through Laravel's intended URL |
| validation failure | back to the page, default error bag | the page it was opened on, `authModal` error bag |
| reset link sent | back to the page with the status | the page it was opened on, modal open in the reset-link state, saying so |
| expected provider failure | `/login` with the message | the page it was opened on, modal open in the mode it started in |
| pending social link | `/login` with the message | the page it was opened on, modal open in **login** mode |

For Google and Facebook the context crosses the provider round trip in the
server-side session and is consumed by the callback.

## When it opens by itself

Only for an explicit reason: the previous request was a form post carrying
`_auth_surface=modal` that failed validation, a reset link requested from
the modal went out, or a provider round trip that started in the modal
ended in an expected failure or a pending link
(`App\Support\Auth\AuthModalState`). Validation errors of any other form on
the page never open it, and its own errors live in the `authModal` bag so
they never appear in another form.

## Screening a sign-up

`POST /register` is the one way to sign up with a password, from the modal,
the standalone page and the form inside a game. After its fields are valid
and before an account exists, `App\Actions\Auth\ScreenRegistrationAction`
checks, in this order:

1. a hidden field people never fill in (`<x-auth.registration-guard>`);
2. how long the form took: a stamp signed for this session, at least two
   seconds old, kept when the form is drawn again after an error;
3. about how many accounts the network created: five an hour and fifteen a
   day per IPv4 address or IPv6 `/64`. A typo spends nothing, and a sign-up
   that loses its address to another one gives its count back, unless it is
   the only one counted in that window. The count is close rather than exact:
   a database failure just before the insert keeps its count, and a release
   landing just after a window ended takes one from the next;
4. the address: a throwaway domain (`propaganistas/laravel-disposable-email`,
   subdomains included, its list updated weekly), or another spelling of a
   Gmail mailbox an account already has (`users.email_canonical`);
5. Cloudflare Turnstile, last, so a mistake elsewhere does not spend its
   single-use token.

`REGISTRATION_SCREENING_MODE` says what happens to what they find:

| mode | effect |
|---|---|
| `off` | nothing is checked (the test suite) |
| `report` | written to the security log, and the sign-up goes through |
| `enforce` | written to the security log, and refused with one generic message |

A blank or unknown value reads as `report`. The log line carries the reasons
and the browser's bot flags, never the name or the address. A refusal only
about the address is shown on the email field, in the words a mistyped or
taken address gets; any other is the one generic message. A new address on
the profile gets the same address check (`ScreenEmailChangeAction`). The
Gmail check reads before the account is written and takes no lock, so two
sign-ups at the same moment can both pass it. Signing in with Google or
Facebook is not screened and matches an exact address only: someone whose
alias another person registered gets a separate account for their own address.

The list of throwaway domains is replaced whole (`DisposableDomainList`). A
failed update keeps the list it had, and a stored list that cannot be read
gives way to the one the package ships with. `pp.ua`, `it.com`, `za.com` and
`msk.ru` are exempt as whole zones (`disposable-email.whitelist`), because they
hold the domains of unrelated people. A throwaway service on one is still
caught by its own entry in the list; one not listed yet passes this check.

When the cache holding the counts cannot be reached, the log line gives the
reason `limits_unavailable`. `report` still lets the sign-up through, and
`enforce` refuses it, because the limits could not be applied.

### Turnstile

Set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` from one widget in the
Cloudflare dashboard, with the site's domains on it. Without both there is no
widget and no check. `resources/js/turnstile.js` loads Cloudflare's script
only when a sign-up form is on screen: the modal in its register state, the
standalone page, or the details step of the form inside a game.

| Cloudflare's answer | `report` | `enforce` |
|---|---|---|
| passed | goes through | goes through |
| missing, wrong, expired or reused token | logged, goes through | refused |
| cannot be reached, or refuses our secret | past one account per address an hour, logged, goes through | one account per address an hour |

A refused secret is also reported as an error. Cloudflare's testing keys work
everywhere except production. A browser without JavaScript cannot pass the
challenge under `enforce`; the form says so, and Google and Facebook still
work.

## The shared modal

`<x-ui.modal>` gained opt-in props, all off by default:

| prop | effect |
|---|---|
| `trap-focus` | focus stays inside, returns to the trigger on close, page scroll is locked while open |
| `close-on-escape` | Escape closes the dialog |
| `fit-viewport` | the dialog never exceeds the viewport: the header stays put, the body scrolls |
| `below-header` | a page-level dialog is laid out between the app header and the bottom of the screen, with equal gaps; used by the image viewers, not by the authentication modal (which lives inside the header and covers it) |

Tests: `tests/Feature/Auth/AuthModal*Test.php`,
`tests/Unit/Support/Auth/AuthReturnUrlTest.php`,
`tests/Browser/AuthModalBrowserTest.php`.
