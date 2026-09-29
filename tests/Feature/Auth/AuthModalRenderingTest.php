<?php

use App\Models\Post;
use App\Models\User;

/*
 * What the pages render: one modal per guest page, two states inside it,
 * the same form components on the standalone pages, and the fixed order —
 * email/password first, providers after it, the switch last.
 */

/** @return list<string> the data-testid values in document order */
function testIdsIn(DOMNode $node): array
{
    $ids = [];

    foreach ((new DOMXPath($node->ownerDocument ?? $node))->query('.//*[@data-testid]', $node) as $element) {
        $ids[] = $element->getAttribute('data-testid');
    }

    return $ids;
}

function pageDocument(string $html): DOMDocument
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return $document;
}

it('renders the modal exactly once for a guest, closed, in login mode', function (string $page) {
    Post::factory()->published()->create();

    $html = $this->get($page)->assertOk()->getContent();
    $modal = authModalElement($html);

    expect(substr_count($html, 'data-testid="auth-modal-root"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="auth-modal"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="auth-modal-login-form"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="auth-modal-register-form"'))->toBe(1)
        ->and($modal->getAttribute('data-auth-modal-open'))->toBe('false')
        ->and($modal->getAttribute('data-auth-modal-mode'))->toBe('login');
})->with(['/', '/about', '/contact']);

it('renders no modal for a signed-in user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertDontSee('data-testid="auth-modal-root"', false)
        ->assertDontSee('data-testid="header-login-link"', false);
});

it('renders no modal on a page that has no session to post back to', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertDontSee('data-auth-modal', false)
        ->assertSee('data-testid="header-login-link"', false);
});

it('opens from the header and from the guest upload button by naming a mode only', function () {
    $document = pageDocument($this->get('/')->assertOk()->getContent());
    $xpath = new DOMXPath($document);
    $handler = fn (string $testId): string => $xpath->evaluate('string(//*[@data-testid="'.$testId.'"]/@*[name()="x-on:click"])');

    expect($handler('header-login-link'))->toBe("rgOpenAuthModal(\$event, 'login')")
        ->and($handler('header-register-link'))->toBe("rgOpenAuthModal(\$event, 'register')")
        ->and($handler('guest-upload-button'))->toStartWith("rgOpenAuthModal(\$event, 'register', ")
        // Still real links, for a page without JavaScript or a new tab.
        ->and($xpath->evaluate('string(//*[@data-testid="header-login-link"]/@href)'))->toBe(route('login'))
        ->and($xpath->evaluate('string(//*[@data-testid="header-register-link"]/@href)'))->toBe(route('register'));
});

it('no longer shows guests the sign-up-required toast', function () {
    $this->get('/')->assertOk()->assertDontSee('Create an account or sign in to upload a post.');
});

it('tells every modal form and provider link which page it was opened on', function () {
    $modal = authModalElement($this->get('/?sort=top&page=2')->assertOk()->getContent());
    $xpath = new DOMXPath($modal->ownerDocument);

    foreach (['login', 'register'] as $mode) {
        $form = './/form[@data-testid="auth-modal-'.$mode.'-form"]';

        expect($xpath->evaluate('string('.$form.'//input[@name="_auth_surface"]/@value)', $modal))->toBe('modal')
            ->and($xpath->evaluate('string('.$form.'//input[@name="_auth_mode"]/@value)', $modal))->toBe($mode)
            ->and($xpath->evaluate('string('.$form.'//input[@name="_auth_return_to"]/@value)', $modal))->toBe('/?sort=top&page=2');

        foreach (['google', 'facebook'] as $provider) {
            $href = $xpath->evaluate('string(.//*[@data-testid="auth-modal-'.$mode.'-panel"]//a[@data-testid="social-'.$provider.'"]/@href)', $modal);
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

            expect(parse_url($href, PHP_URL_PATH))->toBe('/auth/'.$provider)
                ->and($query)->toBe(authModalFields($mode, '/?sort=top&page=2'));
        }
    }
});

it('gives every field of the modal its own id and a label that points at it', function () {
    $modal = authModalElement($this->get('/')->assertOk()->getContent());
    $xpath = new DOMXPath($modal->ownerDocument);

    $ids = [];
    foreach ($xpath->query('.//*[@id]', $modal) as $element) {
        $ids[] = $element->getAttribute('id');
    }

    expect($ids)->toContain(
        'modal-login-email', 'modal-login-password', 'modal-login-remember',
        'modal-register-name', 'modal-register-email', 'modal-register-password', 'modal-register-password-confirmation',
    )->and(array_unique($ids))->toHaveCount(count($ids));

    foreach ($xpath->query('.//label[@for]', $modal) as $label) {
        $target = $xpath->query('.//*[@id="'.$label->getAttribute('for').'"]', $modal);

        expect($target->length)->toBe(1, 'label for="'.$label->getAttribute('for').'" points at nothing');
    }

    expect($xpath->query('.//label[@for]', $modal)->length)->toBe(7);
});

it('does not reuse the ids of the modal on the standalone pages', function (string $path, array $ids) {
    $html = $this->get($path)->assertOk()->getContent();
    $xpath = new DOMXPath(pageDocument($html));

    foreach ($ids as $id) {
        expect($xpath->query('//*[@id="'.$id.'"]')->length)->toBe(1, $id)
            ->and($xpath->query('//label[@for="'.$id.'"]')->length)->toBe(1, 'label for '.$id);
    }

    expect($html)->not->toContain('id="modal-')
        ->and($html)->not->toContain('_auth_surface')
        ->and($html)->not->toContain('data-auth-modal');
})->with([
    ['/login', ['page-login-email', 'page-login-password', 'page-login-remember']],
    ['/register', ['page-register-name', 'page-register-email', 'page-register-password', 'page-register-password-confirmation']],
]);

it('keeps email and password first, the providers after them and the switch last', function (string $surface, string $mode) {
    if ($surface === 'modal') {
        $modal = authModalElement($this->get('/')->assertOk()->getContent());
        $panel = (new DOMXPath($modal->ownerDocument))->query('.//*[@data-testid="auth-modal-'.$mode.'-panel"]', $modal)->item(0);
        $prefix = 'auth-modal-'.$mode;
    } else {
        $panel = pageDocument($this->get('/'.$mode)->assertOk()->getContent())->documentElement;
        $prefix = $mode;
    }

    $order = array_values(array_intersect(testIdsIn($panel), [
        $prefix.'-email',
        $prefix.'-password',
        $prefix.'-submit',
        'auth-divider',
        'social-google',
        'social-facebook',
        'auth-switch-to-'.($mode === 'login' ? 'register' : 'login'),
    ]));

    expect($order)->toBe([
        $prefix.'-email',
        $prefix.'-password',
        $prefix.'-submit',
        'auth-divider',
        'social-google',
        'social-facebook',
        'auth-switch-to-'.($mode === 'login' ? 'register' : 'login'),
    ]);
})->with(['modal', 'page'])->with(['login', 'register']);

it('has no tabs: the two states are switched by the link at the bottom', function () {
    $modal = authModalElement($this->get('/')->assertOk()->getContent());
    $xpath = new DOMXPath($modal->ownerDocument);

    expect($xpath->query('.//*[@role="tab" or @role="tablist"]', $modal)->length)->toBe(0)
        ->and($xpath->evaluate('string(.//button[@data-testid="auth-switch-to-register"]/@*[name()="x-on:click"])', $modal))
        ->toBe("\$dispatch('open-auth-modal', { mode: 'register' })")
        ->and($xpath->evaluate('string(.//button[@data-testid="auth-switch-to-login"]/@*[name()="x-on:click"])', $modal))
        ->toBe("\$dispatch('open-auth-modal', { mode: 'login' })")
        ->and(trim($xpath->evaluate('string(.//*[@data-testid="auth-modal-login-panel"]//p[last()])', $modal)))
        ->toContain(trans('auth.prompts.no_account'))
        ->and(trim($xpath->evaluate('string(.//*[@data-testid="auth-modal-register-panel"]//p[last()])', $modal)))
        ->toContain(trans('auth.prompts.have_account'));
});

it('labels the provider buttons the same in both states and on both pages', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('Log in with Google')
        ->assertSee('Log in with Facebook')
        ->assertDontSee('Continue with Google')
        ->assertDontSee('Sign up with Google');
})->with(['/', '/login', '/register']);

it('switches between the standalone pages with plain links', function () {
    $login = new DOMXPath(pageDocument($this->get('/login')->assertOk()->getContent()));
    $register = new DOMXPath(pageDocument($this->get('/register')->assertOk()->getContent()));

    expect($login->evaluate('string(//a[@data-testid="auth-switch-to-register"]/@href)'))->toBe(route('register'))
        ->and($register->evaluate('string(//a[@data-testid="auth-switch-to-login"]/@href)'))->toBe(route('login'));
});

it('does not open because some other form on the page has errors', function () {
    $this->from('/contact')->post('/contact', ['name' => '', 'email' => 'not-an-email', 'message' => ''])
        ->assertRedirect('/contact');

    $html = $this->get('/contact')->assertOk()->getContent();
    $modal = authModalElement($html);

    // The page does show its own error, and the modal has no part in it.
    expect($html)->toContain(trans('validation.email', ['attribute' => 'email']))
        ->and($modal->getAttribute('data-auth-modal-open'))->toBe('false')
        ->and($modal->textContent)->not->toContain(trans('validation.email', ['attribute' => 'email']))
        ->and((new DOMXPath($modal->ownerDocument))->evaluate('string(.//input[@id="modal-login-email"]/@value)', $modal))->toBe('');
});

it('keeps an authentication error out of the other forms of the page', function () {
    User::factory()->create(['email' => 'ivan@example.com']);

    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'wrong-password'] + authModalFields('login', '/contact'))
        ->assertRedirect('/contact');

    $html = $this->get('/contact')->assertOk()->getContent();
    $modal = authModalElement($html);

    expect(substr_count($html, trans('auth.failed')))->toBe(1)
        ->and($modal->textContent)->toContain(trans('auth.failed'));
});

it('uses the dialog behaviours of the shared modal instead of its own', function () {
    $html = $this->get('/')->assertOk()->getContent();
    $modal = authModalElement($html);
    $xpath = new DOMXPath($modal->ownerDocument);
    $dialog = $xpath->query('.//*[@data-testid="auth-modal"]', $modal)->item(0);

    expect($dialog->getAttribute('role'))->toBe('dialog')
        ->and($dialog->getAttribute('aria-modal'))->toBe('true')
        ->and($dialog->getAttribute('x-on:keydown.escape.window'))->toBe('open = false')
        ->and($xpath->query('.//*[@x-trap.noscroll.noautofocus="open"]', $modal)->length)->toBe(1)
        ->and($xpath->query('.//*[@id="'.$dialog->getAttribute('aria-labelledby').'"]', $modal)->length)->toBe(1)
        // Provider glyphs are decoration: nothing extra for a screen reader.
        ->and($xpath->query('.//a[@data-auth-return-link]//*[local-name()="svg"][not(@aria-hidden="true")]', $modal)->length)->toBe(0);
});
