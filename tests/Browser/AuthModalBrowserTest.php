<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Tests\Browser\Support\MobileViewports;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertGuest;

/*
 * The authentication modal as a person meets it: opened from the header or
 * the guest upload button, switched between its two states, closed again,
 * and brought back by the server after a failed submission.
 */

const AUTH_MODAL = '[data-testid="auth-modal"]';
const LOGIN_PANEL = '[data-testid="auth-modal-login-panel"]';
const REGISTER_PANEL = '[data-testid="auth-modal-register-panel"]';

/** Whether the dialog is on screen, as one expression a test can wait for. */
const AUTH_MODAL_SHOWN = 'getComputedStyle(document.querySelector(\'[data-testid="auth-modal"]\')).display !== "none"';

/**
 * Submits a modal form and waits until the server's answer has replaced the
 * page. A failed submission comes back to the very same URL with the dialog
 * open again, so the URL and the dialog cannot tell the two pages apart: a
 * marker on window can, because only the old document carries it.
 */
function submitAndWaitForNewPage(mixed $page, string $submit): mixed
{
    $page->script('window.__rgPageBeforeSubmit = true');
    $page->click($submit);

    waitForScript($page, 'window.__rgPageBeforeSubmit !== true && document.readyState === "complete"');

    return $page;
}

/** The vertical position of an element inside one of the modal's panels. */
function topOf(string $panel, string $testId): string
{
    return "document.querySelector('{$panel} [data-testid=\"{$testId}\"]').getBoundingClientRect().top";
}

it('opens the login form from the header, with the providers below the submit button', function () {
    $page = visit(route('feed'))
        ->assertMissing(AUTH_MODAL)
        ->click('[data-testid="header-login-link"]')
        ->assertVisible(AUTH_MODAL)
        ->assertVisible('[data-testid="auth-modal-login-form"]')
        ->assertMissing('[data-testid="auth-modal-register-form"]')
        ->assertVisible('[data-testid="auth-modal-title-login"]')
        ->assertSeeIn(LOGIN_PANEL, 'Log in with Google')
        ->assertSeeIn(LOGIN_PANEL, 'Log in with Facebook')
        ->assertPathIs('/');

    $submit = $page->script(topOf(LOGIN_PANEL, 'auth-modal-login-submit'));
    $password = $page->script(topOf(LOGIN_PANEL, 'auth-modal-login-password'));
    $google = $page->script(topOf(LOGIN_PANEL, 'social-google'));
    $facebook = $page->script(topOf(LOGIN_PANEL, 'social-facebook'));
    $switch = $page->script(topOf(LOGIN_PANEL, 'auth-switch-to-register'));

    expect($password)->toBeLessThan($submit)
        ->and($submit)->toBeLessThan($google)
        ->and($google)->toBeLessThan($facebook)
        ->and($facebook)->toBeLessThan($switch);
});

it('switches between login and sign-up without navigating, reloading or closing', function () {
    $page = visit(route('feed', ['sort' => 'top']))
        ->click('[data-testid="header-login-link"]')
        ->assertVisible('[data-testid="auth-modal-login-form"]');

    // A value on window survives only as long as the document does.
    $page->script('window.__rgSameDocument = "yes"');

    $page->click(LOGIN_PANEL.' [data-testid="auth-switch-to-register"]')
        ->assertVisible(AUTH_MODAL)
        ->assertVisible('[data-testid="auth-modal-register-form"]')
        ->assertMissing('[data-testid="auth-modal-login-form"]')
        ->assertVisible('[data-testid="auth-modal-title-register"]')
        ->click(REGISTER_PANEL.' [data-testid="auth-switch-to-login"]')
        ->assertVisible(AUTH_MODAL)
        ->assertVisible('[data-testid="auth-modal-login-form"]')
        ->assertMissing('[data-testid="auth-modal-register-form"]')
        ->assertPathIs('/')
        ->assertQueryStringHas('sort', 'top')
        ->assertScript('window.__rgSameDocument', 'yes');
});

it('puts the focus in the first field and keeps it predictable when the mode changes', function () {
    $page = visit(route('feed'))
        ->click('[data-testid="header-login-link"]')
        ->assertVisible(AUTH_MODAL);

    waitForScript($page, 'document.activeElement.id', 'modal-login-email');

    $page->click(LOGIN_PANEL.' [data-testid="auth-switch-to-register"]');

    waitForScript($page, 'document.activeElement.id', 'modal-register-name');

    $page->click(REGISTER_PANEL.' [data-testid="auth-switch-to-login"]');

    waitForScript($page, 'document.activeElement.id', 'modal-login-email');
});

it('never takes the focus back from a field the person is already in', function () {
    $page = visit(route('feed'));

    // The person reaches the second field before the dialog's own initial
    // focus has run: exactly what a fast click or a password manager does.
    $focused = $page->script(<<<'JS'
        new Promise((resolve) => {
            window.dispatchEvent(new CustomEvent('open-auth-modal', { detail: { mode: 'register' } }));

            window.Alpine.nextTick(() => {
                document.getElementById('modal-register-email').focus();
                resolve(document.activeElement.id);
            });
        })
    JS);

    expect($focused)->toBe('modal-register-email');

    // Longer than the whole initial-focus window: the focus must stay put.
    proveNothingHappensFor($page, 1.5, 'the dialog taking the focus back from the field')
        ->assertScript('document.activeElement.id', 'modal-register-email');
});

it('keeps what is typed in the field it was typed into, however fast', function () {
    $page = visit(route('feed'))->click('[data-testid="header-register-link"]');

    // No pause at all between opening and typing. Then longer than the whole
    // initial-focus window — about a second of retries — for nothing in it to
    // move what was typed: an absence, so a fixed wait.
    $page->type('[data-testid="auth-modal-register-email"]', 'fast@rateguru.test')
        ->type('[data-testid="auth-modal-register-password"]', 'password');

    proveNothingHappensFor($page, 1.5, 'the dialog moving what was typed')
        ->assertValue('[data-testid="auth-modal-register-email"]', 'fast@rateguru.test')
        ->assertValue('[data-testid="auth-modal-register-password"]', 'password')
        ->assertValue('[data-testid="auth-modal-register-name"]', '');
});

it('closes with the close button, with Escape and with the backdrop', function () {
    $page = visit(route('feed'));

    $page->click('[data-testid="header-login-link"]')
        ->assertVisible(AUTH_MODAL)
        ->click(AUTH_MODAL.' [data-testid="modal-close"]');

    waitForScript($page, AUTH_MODAL_SHOWN, false);

    $page->click('[data-testid="header-login-link"]')->assertVisible(AUTH_MODAL);

    waitForScript($page, 'document.activeElement.id', 'modal-login-email');

    $page->keys('#modal-login-email', 'Escape');

    waitForScript($page, AUTH_MODAL_SHOWN, false);

    $page->click('[data-testid="header-register-link"]')
        ->assertVisible(AUTH_MODAL)
        // The element a pointer actually meets at the edge of the screen.
        ->assertScript('document.elementFromPoint(4, 300).dataset.testid', 'modal-backdrop');

    $page->script('document.elementFromPoint(4, 300).click()');

    waitForScript($page, AUTH_MODAL_SHOWN, false);
});

it('locks the page scroll while it is open and gives it back afterwards', function () {
    Post::factory()->published()->count(12)->create();

    $page = visit(route('feed'))
        ->assertScript('document.documentElement.style.overflow', '')
        ->click('[data-testid="header-login-link"]')
        ->assertVisible(AUTH_MODAL);

    waitForScript($page, 'document.documentElement.style.overflow', 'hidden');

    $page->click(AUTH_MODAL.' [data-testid="modal-close"]');

    waitForScript($page, 'document.documentElement.style.overflow', '');
});

it('sits above the header and the page', function () {
    $page = visit(route('feed'))
        ->click('[data-testid="header-login-link"]')
        ->assertVisible(AUTH_MODAL);

    // Where the header's brand link is, the dialog's backdrop is on top.
    waitForScript($page, 'document.elementFromPoint(4, 30).dataset.testid', 'modal-backdrop');
    waitForScript($page, 'document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2).closest(\'[data-testid="auth-modal"]\') !== null');
});

it('opens in registration mode when a guest presses upload, without the old toast', function () {
    visit(route('feed'))
        ->click('[data-testid="guest-upload-button"]')
        ->assertVisible(AUTH_MODAL)
        ->assertVisible('[data-testid="auth-modal-register-form"]')
        ->assertMissing('[data-testid="auth-modal-login-form"]')
        ->assertScript('document.querySelectorAll(\'[data-testid="toast-container"] > div\').length', 0)
        ->assertDontSee('Create an account or sign in to upload a post.')
        ->assertPathIs('/');
});

it('returns to the post the person was reading after signing in', function () {
    $post = Post::factory()->published()->create(['title' => 'Return Here Post']);
    User::factory()->create(['email' => 'modal-post@rateguru.test']);

    $page = visit(route('posts.show', $post))
        ->click('[data-testid="header-login-link"]')
        ->type('[data-testid="auth-modal-login-email"]', 'modal-post@rateguru.test')
        ->type('[data-testid="auth-modal-login-password"]', 'password');

    submitAndWaitForNewPage($page, '[data-testid="auth-modal-login-submit"]')
        ->assertPresent('[data-testid="header-auth-actions"]')
        ->assertPathIs(route('posts.show', $post, absolute: false))
        ->assertSee('Return Here Post');

    assertAuthenticated();
});

it('reopens in registration mode on the same page after a validation error', function () {
    User::factory()->create(['email' => 'taken@rateguru.test']);

    $page = visit(route('feed', ['sort' => 'top']))
        ->click('[data-testid="header-register-link"]')
        ->type('[data-testid="auth-modal-register-name"]', 'Modal Person')
        ->type('[data-testid="auth-modal-register-email"]', 'taken@rateguru.test')
        ->type('[data-testid="auth-modal-register-password"]', 'password')
        ->type('[data-testid="auth-modal-register-password-confirmation"]', 'password');

    submitAndWaitForNewPage($page, '[data-testid="auth-modal-register-submit"]')
        ->assertPathIs('/')
        ->assertQueryStringHas('sort', 'top')
        ->assertVisible(AUTH_MODAL)
        ->assertVisible('[data-testid="auth-modal-register-form"]')
        ->assertMissing('[data-testid="auth-modal-login-form"]')
        ->assertSeeIn(REGISTER_PANEL, 'The email has already been taken.')
        ->assertValue('[data-testid="auth-modal-register-name"]', 'Modal Person')
        ->assertValue('[data-testid="auth-modal-register-email"]', 'taken@rateguru.test')
        ->assertValue('[data-testid="auth-modal-register-password"]', '');

    assertGuest();
});

it('registers from the modal and stays on the page', function () {
    // The browser test server runs inside this process, so the guard hands the
    // very instance RegisterUserAction created to the next request — without
    // the column defaults (status) a real request reads back from the database.
    Event::listen(Registered::class, fn (Registered $event) => $event->user->refresh());

    $page = visit(route('feed', ['sort' => 'hot']))
        ->click('[data-testid="header-register-link"]')
        ->type('[data-testid="auth-modal-register-name"]', 'Modal Newcomer')
        ->type('[data-testid="auth-modal-register-email"]', 'newcomer@rateguru.test')
        ->type('[data-testid="auth-modal-register-password"]', 'password')
        ->type('[data-testid="auth-modal-register-password-confirmation"]', 'password');

    submitAndWaitForNewPage($page, '[data-testid="auth-modal-register-submit"]')
        ->assertPresent('[data-testid="header-auth-actions"]')
        ->assertPathIs('/')
        ->assertQueryStringHas('sort', 'hot');

    assertAuthenticated();
    expect(User::query()->where('email', 'newcomer@rateguru.test')->exists())->toBeTrue();
});

it('points the provider links and the form at the page the person is on now, not the one the server drew', function () {
    $page = visit(route('feed', ['sort' => 'top']));

    // The server drew every return path as /?sort=top. Livewire moves the
    // address without a reload, as it does when the feed is re-sorted: only
    // the modal itself can know the page is somewhere else by now.
    $page->script("() => { history.pushState(null, '', '/?sort=newest'); return true; }");

    $page->click('[data-testid="header-login-link"]')
        ->assertVisible(AUTH_MODAL);

    $href = $page->script('document.querySelector(\''.LOGIN_PANEL.' [data-testid="social-google"]\').href');
    parse_str((string) parse_url((string) $href, PHP_URL_QUERY), $query);

    expect(parse_url((string) $href, PHP_URL_PATH))->toBe('/auth/google')
        ->and($query)->toBe(authModalFields('login', '/?sort=newest'))
        ->and($page->script('document.querySelector(\''.LOGIN_PANEL.' [data-auth-return-input]\').value'))->toBe('/?sort=newest');
});

it('fits a phone screen in both modes, with every control reachable', function (string $trigger, string $panel, string $switch, array $viewport) {
    $page = visit(route('feed'))
        ->resize(...$viewport)
        ->click('[data-testid="'.$trigger.'"]')
        ->assertVisible(AUTH_MODAL)
        ->assertVisible(AUTH_MODAL.' [data-testid="modal-close"]');

    // Settled: the first field has the focus, so nothing will scroll any more.
    waitForScript($page, 'document.activeElement.hasAttribute("data-auth-initial-focus")');

    expect($page->script('document.documentElement.scrollWidth - window.innerWidth'))->toBeLessThanOrEqual(1);

    $dialog = $page->script('(() => { const r = document.querySelector(\''.AUTH_MODAL.' [data-testid="modal-body"]\').parentElement.getBoundingClientRect(); return [r.left, r.right, r.top, r.bottom, window.innerWidth, window.innerHeight]; })()');

    expect($dialog[0])->toBeGreaterThanOrEqual(0)
        ->and($dialog[1])->toBeLessThanOrEqual($dialog[4])
        ->and($dialog[2])->toBeGreaterThanOrEqual(0)
        ->and($dialog[3])->toBeLessThanOrEqual($dialog[5])
        ->and($dialog[1] - $dialog[0])->toBeGreaterThanOrEqual($dialog[4] - 48);

    $reach = $page->script('(() => {
        const body = document.querySelector(\''.AUTH_MODAL.' [data-testid="modal-body"]\');
        const close = document.querySelector(\''.AUTH_MODAL.' [data-testid="modal-close"]\');
        const before = close.getBoundingClientRect().top;
        const inView = (el) => { const r = el.getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight && r.left >= 0 && r.right <= window.innerWidth; };
        const reachable = [...document.querySelectorAll(\''.$panel.' input:not([type=hidden]), '.$panel.' button, '.$panel.' a\')].every((el) => {
            el.scrollIntoView({ block: "center" });
            return inView(el);
        });
        body.scrollTop = body.scrollHeight;
        const last = inView(document.querySelector(\''.$panel.' [data-testid="'.$switch.'"]\'));
        const c = close.getBoundingClientRect();
        return [reachable, last, c.top === before, c.top >= 0 && c.bottom <= window.innerHeight];
    })()');

    expect($reach)->toBe([true, true, true, true]);

    $page->click(AUTH_MODAL.' [data-testid="modal-close"]');

    waitForScript($page, AUTH_MODAL_SHOWN, false);
})->with([
    'login' => ['header-login-link', LOGIN_PANEL, 'auth-switch-to-register'],
    'register' => ['header-register-link', REGISTER_PANEL, 'auth-switch-to-login'],
])->with([
    'small phone' => [MobileViewports::SMALL_MOBILE],
    'phone with the keyboard open' => [[375, 480]],
]);

it('leaves the upload modal of a signed-in user as it was', function () {
    Pest\Laravel\actingAs(User::factory()->create());

    visit(route('feed'))
        ->assertNotPresent('[data-testid="auth-modal-root"]')
        ->click('[data-testid="open-upload-button"]')
        ->assertVisible('[data-testid="upload-modal"]')
        ->assertSee('Create post')
        // The shared modal's new behaviours are opt-in: this one took none.
        ->assertScript('document.documentElement.style.overflow', '')
        ->assertNotPresent('[data-testid="upload-modal"] [data-testid="modal-body"]');
});
