# Authentication modal

Guests sign in and sign up in one dialog that opens over the page they are
on. `/login` and `/register` stay fully functional: they are the fallback
for a direct URL, a new tab, a browser without JavaScript and an error page.

The modal is presentation only. It posts to the same `POST /login` and
`POST /register`, links to the same `/auth/google` and `/auth/facebook`,
and runs the same controllers and actions as the standalone pages. It adds
no endpoint, no JSON API and no second way to authenticate.

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
it; later guest actions (votes, comments, follows) can do the same.

## Two states, no tabs

Login and registration are two states of the one dialog. The link at the
bottom switches between them without navigation, reload or URL change. Each
state is the same composition, `<x-auth.panel>`, in a fixed order:

1. the email/password form (`<x-auth.login-form>` / `<x-auth.register-form>`)
2. its primary button
3. the `or` divider
4. Google, then Facebook (`<x-auth.social-buttons>`)
5. the switch to the other state

The standalone pages render the very same panel, so the two surfaces cannot
drift apart. Email/password stays the primary path; the providers come
after it and read "Log in with …" in both states, because the provider flow
decides on its own whether it signs in, signs up or links.

Both forms are in the DOM at once, so ids are prefixed by surface and mode
(`modal-login-email`, `page-register-name`, …).

## Coming back to the same page

A modal submission marks itself and says where it was opened:

| field | value |
|---|---|
| `_auth_surface` | `modal` |
| `_auth_mode` | `login` or `register` |
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
| expected provider failure | `/login` with the message | the page it was opened on, modal open in the mode it started in |
| pending social link | `/login` with the message | the page it was opened on, modal open in **login** mode |

For Google and Facebook the context crosses the provider round trip in the
server-side session and is consumed by the callback.

## When it opens by itself

Only for an explicit reason: the previous request was a form post carrying
`_auth_surface=modal` that failed validation, or a provider round trip that
started in the modal ended in an expected failure or a pending link
(`App\Support\Auth\AuthModalState`). Validation errors of any other form on
the page never open it, and its own errors live in the `authModal` bag so
they never appear in another form.

## The shared modal

`<x-ui.modal>` gained three opt-in props, all off by default:

| prop | effect |
|---|---|
| `trap-focus` | focus stays inside, returns to the trigger on close, page scroll is locked while open |
| `close-on-escape` | Escape closes the dialog |
| `fit-viewport` | the dialog never exceeds the viewport: the header stays put, the body scrolls |

Tests: `tests/Feature/Auth/AuthModal*Test.php`,
`tests/Unit/Support/Auth/AuthReturnUrlTest.php`,
`tests/Browser/AuthModalBrowserTest.php`.
