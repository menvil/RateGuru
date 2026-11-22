<?php

use App\Actions\Auth\ScreenRegistrationAction;
use App\Enums\RegistrationScreeningMode;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Auth\RegistrationFormStamp;
use Illuminate\Support\Facades\RateLimiter;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/*
 * The checks between a valid sign-up form and a new account, on the three
 * forms that post to it.
 */

/** A sign-up as a person sends it: the stamp the page drew, some seconds ago. */
function screenedSignUp(array $overrides = [], string $page = '/register'): array
{
    $html = test()->get($page)->assertOk()->getContent();

    preg_match('/name="'.RegistrationFormStamp::FIELD.'"\s+value="([^"]+)"/', $html, $stamp);
    test()->travel(10)->seconds();

    return array_merge([
        'name' => 'New Member',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        RegistrationFormStamp::FIELD => $stamp[1] ?? null,
        ScreenRegistrationAction::HONEYPOT_FIELD => '',
    ], $overrides);
}

/** @return list<array<string, mixed>> what the checks wrote to the security log */
function screeningLines(TestHandler $log): array
{
    return collect($log->getRecords())
        ->filter(fn (LogRecord $record): bool => $record->message === ScreenRegistrationAction::EVENT)
        ->map(fn (LogRecord $record): array => $record->context)
        ->values()
        ->all();
}

beforeEach(function () {
    config(['registration.screening_mode' => 'enforce']);
});

it('reads a blank or unknown setting as report, never as off', function (?string $value, RegistrationScreeningMode $mode) {
    config(['registration.screening_mode' => $value]);

    expect(RegistrationScreeningMode::current())->toBe($mode);
})->with([
    'blank' => ['', RegistrationScreeningMode::Report],
    'missing' => [null, RegistrationScreeningMode::Report],
    'a typo' => ['enforced', RegistrationScreeningMode::Report],
    'off' => ['off', RegistrationScreeningMode::Off],
    'report' => ['report', RegistrationScreeningMode::Report],
    'enforce, in capitals' => [' ENFORCE ', RegistrationScreeningMode::Enforce],
]);

it('lets a person through', function () {
    $this->post('/register', screenedSignUp())->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

it('refuses what a script does, with one message that does not say why', function (Closure $change) {
    $response = $this->post('/register', $change(screenedSignUp()));

    $response->assertSessionHasErrors([ScreenRegistrationAction::ERROR_FIELD => __('auth.register.refused')]);
    $this->assertGuest();
    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
})->with([
    'the hidden field filled in' => [fn (array $form) => [...$form, ScreenRegistrationAction::HONEYPOT_FIELD => 'https://spam.example']],
    'no stamp' => [fn (array $form) => array_diff_key($form, [RegistrationFormStamp::FIELD => true])],
    'a made-up stamp' => [fn (array $form) => [...$form, RegistrationFormStamp::FIELD => time().'.'.str_repeat('a', 64)]],
    'a stamp that is not text' => [fn (array $form) => [...$form, RegistrationFormStamp::FIELD => ['a']]],
]);

it('refuses a form sent the moment it was drawn', function () {
    $form = screenedSignUp();
    $form[RegistrationFormStamp::FIELD] = app(RegistrationFormStamp::class)->issue();

    $this->post('/register', $form)->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);
    $this->assertGuest();
});

it('refuses a stamp signed for another session', function () {
    $form = screenedSignUp();
    session()->regenerateToken();

    $this->post('/register', $form)->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);
});

it('keeps the stamp when the form is drawn again, so fixing a typo is not too fast', function () {
    $stamp = app(RegistrationFormStamp::class)->issue();
    $this->travel(30)->seconds();

    expect(app(RegistrationFormStamp::class)->issue($stamp))->toBe($stamp)
        ->and(app(RegistrationFormStamp::class)->ageOf($stamp))->toBe(30);
});

it('limits the accounts one address creates in an hour', function () {
    foreach (range(1, 5) as $count) {
        $form = screenedSignUp(['email' => "member{$count}@example.com"]);
        $this->post('/register', $form)->assertSessionHasNoErrors();
        auth()->logout();
    }

    $this->post('/register', screenedSignUp(['email' => 'sixth@example.com']))
        ->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);

    expect(User::query()->where('email', 'sixth@example.com')->exists())->toBeFalse();
});

it('limits the accounts one address creates in a day', function () {
    RateLimiter::increment(RateLimitKey::registration('day', '127.0.0.1'), 86400, 15);

    $this->post('/register', screenedSignUp())->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);
});

it('does not spend the limit on a typo or on a refusal', function () {
    $key = RateLimitKey::registration('hour', '127.0.0.1');

    $this->post('/register', screenedSignUp(['email' => 'not-an-address']))->assertSessionHasErrors('email');
    $this->post('/register', screenedSignUp([ScreenRegistrationAction::HONEYPOT_FIELD => 'x']))->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);

    expect(RateLimiter::attempts($key))->toBe(0);

    $this->post('/register', screenedSignUp())->assertSessionHasNoErrors();

    expect(RateLimiter::attempts($key))->toBe(1);
});

it('counts one IPv6 network as one client, and two as two', function () {
    expect(RateLimitKey::registration('hour', '2001:db8:1:2:aaaa::1'))->toBe(RateLimitKey::registration('hour', '2001:db8:1:2:bbbb::2'))
        ->and(RateLimitKey::registration('hour', '2001:db8:1:2::1'))->not->toBe(RateLimitKey::registration('hour', '2001:db8:1:3::1'))
        ->and(RateLimitKey::registration('hour', '::ffff:203.0.113.7'))->toBe(RateLimitKey::registration('hour', '203.0.113.7'))
        ->and(RateLimitKey::registration('hour', '203.0.113.7'))->not->toBe(RateLimitKey::registration('hour', '203.0.113.8'))
        ->and(RateLimitKey::registration('hour', '203.0.113.7'))->not->toContain('203.0.113.7');
});

it('only logs in report mode, and the sign-up goes through', function () {
    config(['registration.screening_mode' => 'report']);
    $log = fakeSecurityLog();

    $this->post('/register', screenedSignUp([ScreenRegistrationAction::HONEYPOT_FIELD => 'x']))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    $lines = screeningLines($log);
    expect($lines)->toHaveCount(1);

    $context = $lines[0];
    expect($context)->toMatchArray(['mode' => 'report', 'reasons' => ['honeypot'], 'refused' => false])
        ->and($context)->toHaveKey('bot_flags')
        ->and(json_encode($context))->not->toContain('new@example.com')
        ->and(json_encode($context))->not->toContain('New Member');
});

it('counts an account let through in report mode', function () {
    config(['registration.screening_mode' => 'report']);
    fakeSecurityLog();

    $this->post('/register', screenedSignUp([ScreenRegistrationAction::HONEYPOT_FIELD => 'x']));

    expect(RateLimiter::attempts(RateLimitKey::registration('hour', '127.0.0.1')))->toBe(1);
});

it('logs a refusal, without the name or the address', function () {
    $log = fakeSecurityLog();

    $this->post('/register', screenedSignUp([ScreenRegistrationAction::HONEYPOT_FIELD => 'x']));

    $lines = screeningLines($log);
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray(['mode' => 'enforce', 'reasons' => ['honeypot'], 'refused' => true])
        ->and(json_encode($lines[0]))->not->toContain('new@example.com');
});

it('logs nothing about a person who passes', function () {
    $log = fakeSecurityLog();

    $this->post('/register', screenedSignUp())->assertSessionHasNoErrors();

    expect(screeningLines($log))->toBe([]);
});

it('checks nothing when it is off', function () {
    config(['registration.screening_mode' => 'off']);

    $this->post('/register', [
        'name' => 'New Member',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    expect(RateLimiter::attempts(RateLimitKey::registration('hour', '127.0.0.1')))->toBe(0);
});

it('draws the hidden fields on the standalone page and in the modal', function (string $page, int $forms) {
    $html = $this->get($page)->assertOk()->getContent();

    expect(substr_count($html, 'name="'.RegistrationFormStamp::FIELD.'"'))->toBe($forms)
        ->and(substr_count($html, 'name="'.ScreenRegistrationAction::HONEYPOT_FIELD.'"'))->toBe($forms);
})->with([
    'standalone page' => ['/register', 1],
    'modal over the feed' => ['/', 1],
]);

it('shows a refusal in the modal, on the page it was opened on', function () {
    $form = screenedSignUp([
        ScreenRegistrationAction::HONEYPOT_FIELD => 'x',
        '_auth_surface' => 'modal',
        '_auth_mode' => 'register',
        '_auth_return_to' => '/',
    ], '/');

    $this->post('/register', $form)->assertRedirect('/');

    $this->get('/')
        ->assertSee('data-testid="auth-modal-register-refused"', false)
        ->assertSee(__('auth.register.refused'));
});

it('shows a refusal on the sign-up inside a game, at the step with the button', function () {
    ProjectSettings::factory()->create();
    $draft = publishableGameDraft(1);
    liveGame($draft);
    $page = '/games/'.$draft['game']->slug;

    $html = playToResult($draft['game']->slug)->html();
    preg_match('/name="'.RegistrationFormStamp::FIELD.'"\s+value="([^"]+)"/', $html, $stamp);
    expect($stamp)->not->toBeEmpty();
    $this->travel(10)->seconds();

    $this->post('/register', [
        'name' => 'New Member',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        RegistrationFormStamp::FIELD => $stamp[1],
        ScreenRegistrationAction::HONEYPOT_FIELD => 'x',
        '_auth_surface' => 'embedded',
        '_auth_mode' => 'register',
        '_auth_return_to' => $page,
    ])->assertRedirect($page);

    // The page as the redirect draws it: the attempt resumes at its result.
    $this->withUnencryptedCookie((string) config('games.visitor_cookie.name'), str_repeat('s', 40))
        ->get($page)
        ->assertOk()
        ->assertSee(__('auth.register.refused'))
        ->assertSee("step: 'details'", false);
});
